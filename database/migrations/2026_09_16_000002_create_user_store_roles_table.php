<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_store_roles', function (Blueprint $table) {
            // Which stores a user can reach, replicated from pizzasys via
            // auth.v1.assignment.user_role_store.*.
            //
            // WHY THIS TABLE EXISTS AT ALL, given pizzasys already decides
            // access: pizzasys answers "may THE CALLER touch this store", at
            // request time, for the caller only. Ticket routing has to answer a
            // different question - "may user 812, who is making no request at
            // all, receive a ticket for 03795-00001" - and there is no one to
            // ask. Every other consumer of this data in the estate
            // (NotificationsPizza, AuditApp, LC_PIZZA_DATA) replicates it for
            // the same reason.
            //
            // The caller's own access is still NOT checked against this table.
            // pizzasys stays authoritative there; a stale replica denying a
            // live grant is a worse failure than the one it would prevent.
            $table->unsignedBigInteger('id')->primary();

            $table->unsignedBigInteger('user_id');

            // A STRING holding the store CODE ("03795-00001"), or the literal
            // 'all'. NOT stores.id.
            //
            // This is the single easiest thing to get wrong here: comparing it
            // to an integer primary key silently matches nothing, or - if a
            // store's code happens to look like another store's id - matches
            // the WRONG store. There is a test pinning exactly that case.
            //
            // Nullable only for wire-compatibility with the estate's older
            // rows; our handler always writes 'all'. Both mean every store.
            $table->string('store_id')->nullable();

            // The store-scoped role from pizzasys, e.g. "qa_auditor". Falls
            // back to "role_id_{n}" when the event omits the name, matching the
            // estate so a removal by role name still lines up.
            $table->string('role_name');

            $table->boolean('active')->default(true);

            // Opaque passthrough from pizzasys; nothing here interprets it.
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'store_id']);
            // The batched "which of these candidates has this store" filter.
            $table->index(['store_id', 'active']);

            $table->unique(['user_id', 'store_id', 'role_name'], 'usr_store_role_unique');

            // Fail closed if the role lands before its user: the handler throws,
            // JetStreamConsumer NAKs, and the redelivery succeeds once the user
            // has replicated. UserDeletedHandler keeps local user rows, so the
            // cascade is effectively never exercised.
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_store_roles');
    }
};
