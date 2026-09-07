<?php

namespace Tests\Feature;

use App\Http\Controllers\ClientController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use ReflectionMethod;
use Tests\TestCase;

class CustomerPhoneValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('clients', function (Blueprint $table) {
            $table->increments('id');
            $table->string('phone')->nullable();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('clients');
        parent::tearDown();
    }

    public function test_customer_phone_is_required_and_must_be_exactly_eleven_digits(): void
    {
        $rules = $this->phoneRules();

        $this->assertTrue(Validator::make(['phone' => ''], ['phone' => $rules])->fails());
        $this->assertTrue(Validator::make(['phone' => '3123456789'], ['phone' => $rules])->fails());
        $this->assertTrue(Validator::make(['phone' => '0312-3456789'], ['phone' => $rules])->fails());
        $this->assertTrue(Validator::make(['phone' => '003123456789'], ['phone' => $rules])->fails());
        $this->assertFalse(Validator::make(['phone' => '03123456789'], ['phone' => $rules])->fails());
    }

    public function test_customer_phone_must_be_unique_and_editing_can_keep_its_current_number(): void
    {
        $clientId = DB::table('clients')->insertGetId([
            'phone' => '03123456789',
            'deleted_at' => null,
        ]);

        $this->assertTrue(Validator::make(
            ['phone' => '03123456789'],
            ['phone' => $this->phoneRules()]
        )->fails());

        $this->assertTrue(Validator::make(
            ['phone' => '03123456789'],
            ['phone' => $this->phoneRules($clientId + 1)]
        )->fails());

        $this->assertFalse(Validator::make(
            ['phone' => '03123456789'],
            ['phone' => $this->phoneRules($clientId)]
        )->fails());
    }

    private function phoneRules(?int $ignoreClientId = null): array
    {
        $method = new ReflectionMethod(ClientController::class, 'customerPhoneRules');
        $method->setAccessible(true);

        return $method->invoke(app(ClientController::class), $ignoreClientId);
    }
}
