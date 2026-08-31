<?php

namespace App\Http\Controllers;

use App\Mail\CustomEmail;
use App\Models\Account;
use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\PaymentMethod;
use App\Models\PaymentSale;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\sms_gateway;
use App\Models\SMSMessage;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use DB;
use GuzzleHttp\Client as Client_guzzle;
use GuzzleHttp\Client as Client_termi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Infobip\Api\SendSmsApi;
use Infobip\Configuration;
use Infobip\Model\SmsAdvancedTextualRequest;
use Infobip\Model\SmsDestination;
use Infobip\Model\SmsTextualMessage;
use PDF;
use Twilio\Rest\Client as Client_Twilio;

class PaymentSalesController extends BaseController
{
    // ------------- Get All Payments Sales --------------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Reports_payments_Sales', PaymentSale::class);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        
        $warehouse_ids = [];
        $is_all_warehouses = $user->is_all_warehouses;
        // If the user is restricted, fetch their assigned warehouse IDs once and reuse below.
        if (! $is_all_warehouses) {
            $warehouse_ids = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }
        
        // Filter fields With Params to retriever
        $param = [0 => 'like', 1 => '=', 2 => '='];
        $columns = [0 => 'Ref', 1 => 'sale_id', 2 => 'payment_method_id'];
        $data = [];

        // Check If User Has Permission View  All Records
        $Payments = PaymentSale::with('sale.client', 'account', 'user')
            ->where('deleted_at', '=', null)
            ->whereBetween('date', [$request->from, $request->to])
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });
            // ✅ Restrict by user's warehouses (through sale.warehouse_id)
        if (! $is_all_warehouses) {
            $Payments->whereHas('sale', function ($q) use ($warehouse_ids) {
                $q->whereIn('warehouse_id', $warehouse_ids);
            });
        }
        // Multiple Filter
            $Payments->where(function ($query) use ($request) {
                return $query->when($request->filled('client_id'), function ($query) use ($request) {
                    return $query->whereHas('sale.client', function ($q) use ($request) {
                        $q->where('id', '=', $request->client_id);
                    });
                });
            });
        $Filtred = $helpers->filter($Payments, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('date', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('sale', function ($q) use ($request) {
                                $q->where('Ref', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('payment_method', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('sale.client', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $Payments = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($Payments as $Payment) {

            $item['date'] = $Payment->date;
            $item['Ref'] = $Payment->Ref;
            $item['Ref_Sale'] = $Payment['sale']->Ref;
            $item['sale_id'] = $Payment['sale']->id;
            $item['client_name'] = $Payment['sale']['client']->name;
            $item['payment_method'] = $Payment['payment_method']->name;
            $item['montant'] = $Payment->montant;
            $item['account_name'] = $Payment['account'] ? $Payment['account']->account_name : '---';
            $item['user_name'] = $Payment['user'] ? $Payment['user']->username : '---';
            $data[] = $item;
        }

        $clients = Client::where('deleted_at', '=', null)->get(['id', 'name']);
        $sales = Sale::get(['Ref', 'id']);
        $payment_methods = PaymentMethod::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'totalRows' => $totalRows,
            'payments' => $data,
            'sales' => $sales,
            'clients' => $clients,
            'payment_methods' => $payment_methods,
        ]);

    }

    // ----------- Store new Payment Sale --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', PaymentSale::class);

        $validated = $request->validate([
            'sale_id' => 'required|integer|exists:sales,id',
            'date' => 'required|date',
            'montant' => 'required|numeric|gt:0',
            'received_amount' => 'nullable|numeric|min:0',
            'change' => 'nullable|numeric|min:0',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'account_id' => 'nullable|integer|exists:accounts,id',
            'notes' => 'nullable|string',
        ]);

        \DB::transaction(function () use ($request, $validated) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            // Serialize payments for the same sale. A repeated or concurrent
            // request will see the balance left by the first committed payment.
            $sale = Sale::whereNull('deleted_at')
                ->whereKey($validated['sale_id'])
                ->lockForUpdate()
                ->firstOrFail();

            // Check If User Has Permission view All Records
            if (! $view_records) {
                // Check If User->id === sale->id
                $this->authorizeForUser($request->user('api'), 'check_record', $sale);
            }

            $amount = round((float) $validated['montant'], 2);
            $grandTotal = round((float) $sale->GrandTotal, 2);
            $alreadyPaid = round((float) $sale->paid_amount, 2);
            $currentDue = round($grandTotal - $alreadyPaid, 2);

            if ($currentDue <= 0) {
                throw ValidationException::withMessages([
                    'montant' => ['This sale is already fully paid. Refresh the sales list before adding another payment.'],
                ]);
            }

            if ($amount > $currentDue) {
                throw ValidationException::withMessages([
                    'montant' => ['Payment cannot exceed the remaining due of '.number_format($currentDue, 2, '.', '').'.'],
                ]);
            }

            $totalPaid = round($alreadyPaid + $amount, 2);
            $remainingDue = round($grandTotal - $totalPaid, 2);
            $paymentStatus = $remainingDue <= 0 ? 'paid' : 'partial';

            PaymentSale::create([
                'sale_id' => $sale->id,
                'Ref' => app('App\Http\Controllers\PaymentSalesController')->getNumberOrder(),
                'date' => $validated['date'],
                'account_id' => $validated['account_id'] ?? null,
                'payment_method_id' => $validated['payment_method_id'],
                'montant' => $amount,
                'change' => $validated['change'] ?? 0,
                'notes' => $validated['notes'] ?? null,
                'user_id' => Auth::user()->id,
            ]);

            if (! empty($validated['account_id'])) {
                $account = Account::whereKey($validated['account_id'])->lockForUpdate()->first();
                if ($account) {
                    $account->update([
                        'balance' => $account->balance + $amount,
                    ]);
                }
            }

            $sale->update([
                'paid_amount' => $totalPaid,
                'payment_statut' => $paymentStatus,
            ]);
        }, 10);

        return response()->json(['success' => true, 'message' => 'Payment Create successfully'], 200);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // ----------- Update Payments Sale --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', PaymentSale::class);

        $validated = $request->validate([
            'date' => 'required|date',
            'montant' => 'required|numeric|gt:0',
            'received_amount' => 'nullable|numeric|min:0',
            'change' => 'nullable|numeric|min:0',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'account_id' => 'nullable|integer|exists:accounts,id',
            'notes' => 'nullable|string',
        ]);

        \DB::transaction(function () use ($id, $request, $validated) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $payment = PaymentSale::whereKey($id)->lockForUpdate()->firstOrFail();

            // Check If User Has Permission view All Records
            if (! $view_records) {
                // Check If User->id === payment->id
                $this->authorizeForUser($request->user('api'), 'check_record', $payment);
            }

            if ((int) $payment->payment_method_id === 1) {
                throw ValidationException::withMessages([
                    'montant' => ['Card payments cannot be edited.'],
                ]);
            }

            $sale = Sale::whereKey($payment->sale_id)->lockForUpdate()->firstOrFail();
            $amount = round((float) $validated['montant'], 2);
            $paidWithoutThisPayment = round(max(0, (float) $sale->paid_amount - (float) $payment->montant), 2);
            $maximumPayment = round((float) $sale->GrandTotal - $paidWithoutThisPayment, 2);

            if ($amount > $maximumPayment) {
                throw ValidationException::withMessages([
                    'montant' => ['Payment cannot exceed the available balance of '.number_format($maximumPayment, 2, '.', '').'.'],
                ]);
            }

            $newTotalPaid = round($paidWithoutThisPayment + $amount, 2);
            $remainingDue = round((float) $sale->GrandTotal - $newTotalPaid, 2);
            $paymentStatus = $remainingDue <= 0
                ? 'paid'
                : ($newTotalPaid > 0 ? 'partial' : 'unpaid');

            $accountIds = collect([$payment->account_id, $validated['account_id'] ?? null])
                ->filter()
                ->unique()
                ->sort()
                ->values();
            $accounts = Account::whereIn('id', $accountIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($payment->account_id && $accounts->has($payment->account_id)) {
                $oldAccount = $accounts->get($payment->account_id);
                $oldAccount->update([
                    'balance' => $oldAccount->balance - (float) $payment->montant,
                ]);
            }

            $payment->update([
                'date' => $validated['date'],
                'payment_method_id' => $validated['payment_method_id'],
                'account_id' => $validated['account_id'] ?? null,
                'montant' => $amount,
                'change' => $validated['change'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            if (! empty($validated['account_id']) && $accounts->has($validated['account_id'])) {
                $newAccount = $accounts->get($validated['account_id']);
                $newAccount->refresh();
                $newAccount->update([
                    'balance' => $newAccount->balance + $amount,
                ]);
            }

            $sale->update([
                'paid_amount' => $newTotalPaid,
                'payment_statut' => $paymentStatus,
            ]);
        }, 10);

        return response()->json(['success' => true, 'message' => 'Payment Update successfully'], 200);
    }

    // ----------- Delete Payment Sales --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PaymentSale::class);

        \DB::transaction(function () use ($id, $request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $payment = PaymentSale::findOrFail($id);

            // Check If User Has Permission view All Records
            if (! $view_records) {
                // Check If User->id === payment->id
                $this->authorizeForUser($request->user('api'), 'check_record', $payment);
            }

            $sale = Sale::find($payment->sale_id);
            $total_paid = $sale->paid_amount - $payment->montant;
            $due = $sale->GrandTotal - $total_paid;

            if ($due === 0.0 || $due < 0.0) {
                $payment_statut = 'paid';
            } elseif ($due !== $sale->GrandTotal) {
                $payment_statut = 'partial';
            } elseif ($due === $sale->GrandTotal) {
                $payment_statut = 'unpaid';
            }

            PaymentSale::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            $account = Account::where('id', $payment->account_id)->exists();

            if ($account) {
                // Account exists, perform the update
                $account = Account::find($payment->account_id);
                $account->update([
                    'balance' => $account->balance - $payment->montant,
                ]);
            }

            $sale->update([
                'paid_amount' => $total_paid,
                'payment_statut' => $payment_statut,
            ]);

        }, 10);

        return response()->json(['success' => true, 'message' => 'Payment Delete successfully'], 200);

    }

    // ----------- Reference order Payment Sales --------------\\

    public function getNumberOrder()
    {
        $last = DB::table('payment_sales')->latest('id')->first();

        if ($last) {
            $item = (string) ($last->Ref ?? '');

            // Accept either '_' or '-' as separator without assuming array index exists
            if (strpos($item, '_') !== false) {
                $nwMsg = explode('_', $item);
                $sep = '_';
            } elseif (strpos($item, '-') !== false) {
                $nwMsg = explode('-', $item);
                $sep = '-';
            } else {
                $nwMsg = [];
                $sep = '_';
            }

            if (isset($nwMsg[0], $nwMsg[1]) && is_numeric($nwMsg[1])) {
                $inMsg = $nwMsg[1] + 1;
                $code = $nwMsg[0].$sep.$inMsg;
            } else {
                $code = 'INV/SL_1111';
            }

        } else {
            $code = 'INV/SL_1111';
        }

        return $code;
    }

    // ----------- Payment Sale PDF --------------\\

    public function payment_sale(Request $request, $id)
    {
        $payment = PaymentSale::with('sale', 'sale.client')->findOrFail($id);

        $payment_data['sale_Ref'] = $payment['sale']->Ref;
        $payment_data['client_name'] = $payment['sale']['client']->name;
        $payment_data['client_phone'] = $payment['sale']['client']->phone;
        $payment_data['client_adr'] = $payment['sale']['client']->adresse;
        $payment_data['client_email'] = $payment['sale']['client']->email;
        $payment_data['montant'] = $payment->montant;
        $payment_data['Ref'] = $payment->Ref;
        $payment_data['date'] = $payment->date;
        $payment_data['payment_method'] = $payment['payment_method']->name;

        $helpers = new helpers;
        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $Html = view('pdf.payment_sale', [
            'symbol' => $symbol,
            'setting' => $settings,
            'payment' => $payment_data,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('Payment_Sale.pdf');

    }

    // ------------- Send Payment Sale on Email -----------\\

    public function SendEmail(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PaymentSale::class);
        // PaymentSale
        $payment = PaymentSale::with('sale.client')->findOrFail($request->id);

        $helpers = new helpers;
        $currency = $helpers->Get_Currency();

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        // the custom msg of payment_received
        $emailMessage = EmailMessage::getForLocale('payment_received');

        if ($emailMessage) {
            $message_body = $emailMessage->body;
            $message_subject = $emailMessage->subject;
        } else {
            $message_body = '';
            $message_subject = '';
        }

        $payment_number = $payment->Ref;

        $total_amount = $currency.' '.number_format($payment->montant, 2, '.', ',');

        $contact_name = $payment['sale']['client']->name;
        $business_name = $settings->CompanyName;

        // receiver email
        $receiver_email = $payment['sale']['client']->email;

        // replace the text with tags
        $message_body = str_replace('{contact_name}', $contact_name, $message_body);
        $message_body = str_replace('{business_name}', $business_name, $message_body);
        $message_body = str_replace('{payment_number}', $payment_number, $message_body);
        $message_body = str_replace('{total_amount}', $total_amount, $message_body);

        $email['subject'] = $message_subject;
        $email['body'] = $message_body;
        $email['company_name'] = $business_name;

        $this->Set_config_mail();

        Mail::to($receiver_email)->send(new CustomEmail($email));

        return response()->json(['message' => 'Email sent successfully'], 200);

        // return $mail;
    }

    // -------------------Sms Notifications -----------------\\

    public function Send_SMS(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PaymentSale::class);

        // PaymentSale
        $payment = PaymentSale::with('sale.client')->findOrFail($request->id);

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        $default_sms_gateway = sms_gateway::where('id', $settings->sms_gateway)
            ->where('deleted_at', '=', null)->first();

        $helpers = new helpers;
        $currency = $helpers->Get_Currency();

        // the custom msg of payment_received
        $smsMessage = SMSMessage::getForLocale('payment_received');

        if ($smsMessage) {
            $message_text = $smsMessage->text;
        } else {
            $message_text = '';
        }

        $payment_number = $payment->Ref;

        $total_amount = $currency.' '.number_format($payment->montant, 2, '.', ',');

        $contact_name = $payment['sale']['client']->name;
        $business_name = $settings->CompanyName;

        // receiver phone
        $receiverNumber = $payment['sale']['client']->phone;

        // replace the text with tags
        $message_text = str_replace('{contact_name}', $contact_name, $message_text);
        $message_text = str_replace('{business_name}', $business_name, $message_text);
        $message_text = str_replace('{payment_number}', $payment_number, $message_text);
        $message_text = str_replace('{total_amount}', $total_amount, $message_text);

        // twilio
        if ($default_sms_gateway->title == 'twilio') {
            try {

                $account_sid = env('TWILIO_SID');
                $auth_token = env('TWILIO_TOKEN');
                $twilio_number = env('TWILIO_FROM');

                $client = new Client_Twilio($account_sid, $auth_token);
                $client->messages->create($receiverNumber, [
                    'from' => $twilio_number,
                    'body' => $message_text]);

            } catch (Exception $e) {
                return response()->json(['message' => $e->getMessage()], 500);
            }
        }
        // termii
        elseif ($default_sms_gateway->title == 'termii') {

            $client = new Client_termi;
            $url = 'https://api.ng.termii.com/api/sms/send';

            $payload = [
                'to' => $receiverNumber,
                'from' => env('TERMI_SENDER'),
                'sms' => $message_text,
                'type' => 'plain',
                'channel' => 'generic',
                'api_key' => env('TERMI_KEY'),
            ];

            try {
                $response = $client->post($url, [
                    'json' => $payload,
                ]);

                $result = json_decode($response->getBody(), true);

                return response()->json($result);
            } catch (\Exception $e) {
                Log::error('Termii SMS Error: '.$e->getMessage());

                return response()->json(['status' => 'error', 'message' => 'Failed to send SMS'], 500);
            }

        }
        // ---- infobip
        elseif ($default_sms_gateway->title == 'infobip') {

            $BASE_URL = env('base_url');
            $API_KEY = env('api_key');
            $SENDER = env('sender_from');

            $configuration = (new Configuration)
                ->setHost($BASE_URL)
                ->setApiKeyPrefix('Authorization', 'App')
                ->setApiKey('Authorization', $API_KEY);

            $client = new Client_guzzle;

            $sendSmsApi = new SendSMSApi($client, $configuration);
            $destination = (new SmsDestination)->setTo($receiverNumber);
            $message = (new SmsTextualMessage)
                ->setFrom($SENDER)
                ->setText($message_text)
                ->setDestinations([$destination]);

            $request = (new SmsAdvancedTextualRequest)->setMessages([$message]);

            try {
                $smsResponse = $sendSmsApi->sendSmsMessage($request);
                echo 'Response body: '.$smsResponse;
            } catch (Throwable $apiException) {
                echo 'HTTP Code: '.$apiException->getCode()."\n";
            }

        }
        // ---- custom
        elseif ($default_sms_gateway->title == 'custom') {
            try {
                (new \App\Services\CustomSmsGateway)->send($receiverNumber, $message_text);
            } catch (\Throwable $e) {
                Log::error('Custom SMS Error: '.$e->getMessage());

                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
            }
        }

        return response()->json(['success' => true]);
    }
}
