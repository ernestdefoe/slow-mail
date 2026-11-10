<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// A member who stopped letters from someone. Checked both ways when writing.
return Migration::createTable('slow_mail_blocks', function (Blueprint $table) {
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('blocked_id');
    $table->dateTime('created_at');

    $table->primary(['user_id', 'blocked_id']);
    $table->index('blocked_id');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('blocked_id')->references('id')->on('users')->cascadeOnDelete();
});
