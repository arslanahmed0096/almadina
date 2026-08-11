<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class UserLoginSession extends Model
{
    public const HISTORY_RETENTION_HOURS = 48;

    protected $table = 'user_login_sessions';

    protected $fillable = [
        'user_id',
        'access_token_id',
        'ip_address',
        'user_agent',
        'logged_in_at',
        'last_activity_at',
        'revoked_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'logged_in_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken()
    {
        return $this->belongsTo(OauthAccessToken::class, 'access_token_id', 'id');
    }

    public static function retentionCutoff(): CarbonInterface
    {
        return now()->subHours(self::HISTORY_RETENTION_HOURS);
    }

    public function scopeWithinHistoryRetention($query, ?CarbonInterface $cutoff = null)
    {
        $cutoff = $cutoff ?: self::retentionCutoff();

        return $query->where(function ($query) use ($cutoff) {
            $query->where('last_activity_at', '>=', $cutoff)
                ->orWhere(function ($query) use ($cutoff) {
                    $query->whereNull('last_activity_at')
                        ->where('logged_in_at', '>=', $cutoff);
                })
                ->orWhere(function ($query) use ($cutoff) {
                    $query->whereNull('last_activity_at')
                        ->whereNull('logged_in_at')
                        ->where('created_at', '>=', $cutoff);
                });
        });
    }

    /**
     * Remove activity history older than 48 hours. Revoked cookie-session rows
     * are retained as security tombstones so an old cookie cannot be revived.
     */
    public static function purgeExpiredHistory(): int
    {
        $cutoff = self::retentionCutoff();

        return self::query()
            ->where(function ($query) use ($cutoff) {
                $query->where('last_activity_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff) {
                        $query->whereNull('last_activity_at')
                            ->where('logged_in_at', '<', $cutoff);
                    })
                    ->orWhere(function ($query) use ($cutoff) {
                        $query->whereNull('last_activity_at')
                            ->whereNull('logged_in_at')
                            ->where('created_at', '<', $cutoff);
                    });
            })
            ->where(function ($query) {
                $query->where('access_token_id', 'not like', 'cookie:%')
                    ->orWhereNull('revoked_at');
            })
            ->delete();
    }
}



























































