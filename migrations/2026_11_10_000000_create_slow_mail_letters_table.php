<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * One row per letter. The words are encrypted (Vault) before they reach the
 * database. Where it came from is only ever a postmark (a region or a
 * country, never a city) and only when the sender is on Member Map for the
 * recipient; the distance itself is never stored, only the journey's hours.
 * 🚨 No hand-named indexes or foreign keys: Laravel's names carry the prefix.
 */
return [
    'up' => function (Builder $schema) {
        $schema->create('slow_mail_letters', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('sender_id');
            $table->unsignedInteger('recipient_id');
            $table->unsignedInteger('reply_to_id')->nullable();
            $table->mediumText('body');
            $table->unsignedInteger('words')->default(0);
            $table->string('stamp', 20);
            $table->string('postmark', 100)->nullable();
            $table->string('origin_country', 2)->nullable();
            $table->string('destination', 100)->nullable();
            $table->unsignedSmallInteger('travel_hours');
            $table->dateTime('sent_at');
            $table->dateTime('arrives_at');
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->boolean('sender_hidden')->default(false);
            $table->boolean('recipient_hidden')->default(false);

            $table->index(['delivered_at', 'arrives_at']);
            $table->index(['sender_id', 'sent_at']);
            $table->index(['recipient_id', 'delivered_at']);
            $table->foreign('sender_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('recipient_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('slow_mail_letters');
    },
];
