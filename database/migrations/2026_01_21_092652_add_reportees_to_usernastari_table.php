<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('usernastari', function (Blueprint $table) {
            $table->text('direct_reportees_employee_id')->nullable()->after('company_email_id');
        });
    }

    public function down()
    {
        Schema::table('usernastari', function (Blueprint $table) {
            $table->dropColumn('direct_reportees_employee_id');
        });
    }
};