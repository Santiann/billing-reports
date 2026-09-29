<?php

use App\Domain\User\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The user's access role.
 *
 * The column's default is `viewer`, the least privilege: a user created through a path that
 * forgot to set the role does not go off writing. That is the safe behaviour when someone slips.
 *
 * The users that ALREADY EXIST become administrators, and that does not contradict the default:
 * before this migration there was no other role, so whoever was already in there was an
 * administrator by definition. Applying the default to them would strip write access from
 * whoever was already operating the system.
 *
 * A short string and not a MySQL ENUM, for the same reason as the customer's status: adding a
 * role becomes a code change, not an ALTER TABLE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)
                ->default(UserRole::Viewer->value)
                ->after('password');
        });

        DB::table('users')->update(['role' => UserRole::Admin->value]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
