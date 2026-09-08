<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The business trades as Cane & Cotton Laundry. The old name was baked into
     * the seeded business name and into the stored SMS copy, along with a
     * Facebook page belonging to the previous business.
     *
     * Templates are rewritten by targeted replacement rather than being reset to
     * the defaults, so any wording the owner has customised is preserved.
     */
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        DB::table('system_settings')
            ->whereIn('business_name', ['SPIN KLEAN LAUNDRY', 'Spin Klean Laundry'])
            ->update(['business_name' => 'Cane & Cotton Laundry']);

        $templateColumns = [
            'sms_template_order_received',
            'sms_template_delivery_received',
            'sms_template_ready_for_pickup',
            'sms_template_ready_for_delivery',
            'sms_template_completed',
        ];

        // {store} resolves to the configured business name in SmsNotifier, so
        // the copy follows this and any future rename on its own.
        $replacements = [
            "\nFacebook:\nhttps://www.facebook.com/spinkleanlaundryCDO" => '',
            "\nFacebook: Spin Klean Laundry CDO" => '',
            'Spin Klean {branch}' => '{store} {branch}',
            'Spin Klean Laundry' => '{store}',
            'Spin Klean' => '{store}',
        ];

        foreach (DB::table('system_settings')->get() as $row) {
            $changes = [];

            foreach ($templateColumns as $column) {
                $value = $row->{$column} ?? null;

                if (! is_string($value) || $value === '') {
                    continue;
                }

                $updated = str_replace(array_keys($replacements), array_values($replacements), $value);

                if ($updated !== $value) {
                    $changes[$column] = $updated;
                }
            }

            if ($changes !== []) {
                DB::table('system_settings')->where('id', $row->id)->update($changes);
            }
        }
    }

    public function down(): void
    {
        // A rename is not meaningfully reversible: the previous SMS wording
        // cannot be recovered from the rewritten text.
    }
};
