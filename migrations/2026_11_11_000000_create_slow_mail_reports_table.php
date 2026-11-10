<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A letter its recipient chose to show the moderators. The words are copied
 * (encrypted, Vault) at the moment of reporting, so the report stands even if
 * the letter is put away. 🚨 No hand-named indexes or foreign keys.
 */
return [
    'up' => function (Builder $schema) {
        $schema->create('slow_mail_reports', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('letter_id')->nullable();
            $table->unsignedInteger('reporter_id');
            $table->unsignedInteger('sender_id');
            $table->mediumText('body');
            $table->string('reason', 500)->default('');
            $table->dateTime('letter_sent_at');
            $table->dateTime('created_at');
            $table->dateTime('resolved_at')->nullable();
            $table->unsignedInteger('resolved_by')->nullable();

            $table->index(['resolved_at', 'created_at']);
            $table->index(['reporter_id', 'created_at']);
            $table->foreign('letter_id')->references('id')->on('slow_mail_letters')->nullOnDelete();
            $table->foreign('reporter_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('sender_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('slow_mail_reports');
    },
];
