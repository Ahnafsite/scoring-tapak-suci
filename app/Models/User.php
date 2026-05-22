<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'role_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    private const SENI_THREE_JURY_NUMBERS = [1, 2, 3];

    private const SENI_FIVE_JURY_NUMBERS = [1, 2, 3, 4, 5];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return list<int>
     */
    public static function activeSeniJuryNumbers(): array
    {
        $activeSince = now()
            ->subMinutes((int) config('session.lifetime', 120))
            ->getTimestamp();

        return static::query()
            ->select('users.name')
            ->distinct()
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->join(config('session.table', 'sessions'), 'sessions.user_id', '=', 'users.id')
            ->where('roles.name', 'Juri')
            ->where('sessions.last_activity', '>=', $activeSince)
            ->pluck('users.name')
            ->map(static function (string $name): ?int {
                preg_match('/\d+/', $name, $matches);

                $juryNumber = isset($matches[0]) ? (int) $matches[0] : null;

                return $juryNumber !== null && $juryNumber >= 1 && $juryNumber <= 5
                    ? $juryNumber
                    : null;
            })
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    public static function seniScoringJuryNumbers(): array
    {
        $activeJuryNumbers = static::activeSeniJuryNumbers();

        return count($activeJuryNumbers) === count(self::SENI_FIVE_JURY_NUMBERS)
            ? self::SENI_FIVE_JURY_NUMBERS
            : self::SENI_THREE_JURY_NUMBERS;
    }
}
