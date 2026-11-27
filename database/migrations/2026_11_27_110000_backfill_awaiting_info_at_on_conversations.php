<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * «Esperando información del cliente» for the requests already returned
     * for missing information before the state existed: pending, without
     * agent, «Necesita información» and marked so by a person. They leave
     * «Pendientes» from the moment they were marked (readiness_changed_at, or
     * now when it is missing). Two bounded UPDATEs over the pending rows
     * (the status index), touching only rows not stamped yet, so running it
     * again changes nothing. Requests born «Necesita información» by the
     * confirmation rule (nobody marked them) are left as they are.
     */
    public function up(): void
    {
        $returned = fn () => DB::table('conversations')
            ->where('status', 'pending')
            ->whereNull('assigned_user_id')
            ->where('readiness', 'needs_info')
            ->whereNotNull('readiness_changed_by_user_id')
            ->whereNull('awaiting_info_at');

        // Since they were marked; the few without that moment, from now (the
        // application's clock, never the database's).
        $returned()->whereNotNull('readiness_changed_at')->update(['awaiting_info_at' => DB::raw('readiness_changed_at')]);
        $returned()->update(['awaiting_info_at' => now()]);
    }

    /**
     * Nothing to undo destructively: dropping the column (the previous
     * migration's down()) removes the stamp; leaving it set is harmless for
     * code that does not read it.
     */
    public function down(): void
    {
        //
    }
};
