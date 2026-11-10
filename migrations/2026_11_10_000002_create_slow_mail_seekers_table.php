<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Members looking for a pen pal: opted in, with interests and where they'd like one to live.
return Migration::createTable('slow_mail_seekers', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->string('interests', 255)->default('');
    $table->string('region', 16)->default('anywhere');
    $table->dateTime('created_at');
    $table->dateTime('updated_at');

    $table->index('updated_at');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
