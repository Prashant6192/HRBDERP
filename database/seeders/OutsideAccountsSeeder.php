<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Marketplace\Models\Brand;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Logins for people outside the company. They are not employees: no
 * employee code, no department, not on the Employees list, and they sign
 * in with a username.
 *
 * Each account is created once. A deploy that runs this again never
 * touches it, so a password changed after the first sign-in stays changed
 * and an account the administrator has switched off stays off.
 */
class OutsideAccountsSeeder extends Seeder
{
    /**
     * The digital agency that runs the marketplace accounts. Its starting
     * password is stored only as a hash here; the agency should change it
     * under Settings → Password after the first sign-in.
     */
    private const AGENCY = [
        'username' => 'divrit_processing',
        'name' => 'Divrit (Digital Agency)',
        // Not a mailbox: .invalid can never receive mail, so nothing is
        // ever sent to it. The agency signs in with the username.
        'email' => 'divrit_processing@agency.invalid',
        'password_hash' => '$2y$12$I/nffFZ6gs2u.96taOglw.zFwvInArK6Tm00UwMjp2wAktVqZsnq6',
    ];

    public function run(): void
    {
        if (! Schema::hasColumn('users', 'username') || ! Schema::hasTable('brands')) {
            return;
        }

        $exists = User::withTrashed()
            ->where('username', self::AGENCY['username'])
            ->orWhere('email', self::AGENCY['email'])
            ->exists();

        if ($exists) {
            return;
        }

        $agency = new User;
        $agency->forceFill([
            'username' => self::AGENCY['username'],
            'name' => self::AGENCY['name'],
            'email' => self::AGENCY['email'],
            // Replaced below by the stored hash, written as is.
            'password' => Str::random(40),
            'status' => UserStatus::Active,
            'is_external' => true,
            'designation' => 'E-commerce agency (outside the company)',
            'email_verified_at' => now(),
        ])->save();

        // The hash goes in untouched: the model would re-check it against the
        // hashing settings of wherever this runs.
        DB::table('users')->where('id', $agency->id)->update(['password' => self::AGENCY['password_hash']]);

        $agency->assignRole(RoleName::EcommerceAgency->value);

        // It uploads for the brands sold online; the Brands screen can take
        // one away.
        $agency->brands()->syncWithoutDetaching(Brand::query()->pluck('id')->all());

        $this->command?->info('Outside account created: '.self::AGENCY['username'].' (E-commerce Agency).');
    }
}
