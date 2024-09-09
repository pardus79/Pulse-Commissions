(function($) {
    $(document).ready(function() {
        var setupIndex = $('#payout-setups .payout-setup').length;
        var currency = pulseCommissionsAdmin.currency;

        // Add Payout Setup
        $('#add-payout-setup').on('click', function() {
            var template = wp.template('payout-setup');
            $('#payout-setups').append(template({index: setupIndex++}));
        });

        // Add Payout
        $(document).on('click', '.add-payout', function() {
            var $setup = $(this).closest('.payout-setup');
            var setupIndex = $setup.index();
            var payoutIndex = $setup.find('.payout').length;
            var template = wp.template('payout');
            $setup.find('.payouts').append(template({setupIndex: setupIndex, payoutIndex: payoutIndex}));
        });

        // Remove Payout Setup
        $(document).on('click', '.remove-payout-setup', function() {
            $(this).closest('.payout-setup').remove();
        });

        // Remove Payout
        $(document).on('click', '.remove-payout', function() {
            $(this).closest('.payout').remove();
        });

        // Update currency display when payout type changes
        $(document).on('change', 'select[name$="[payout_type]"]', function() {
            var $currency = $(this).closest('.payout').find('.currency');
            $currency.text($(this).val() === 'flat_rate' ? currency : '%');
        });
    });
})(jQuery);