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

        // Handle notice dismissal
        $(document).on('click', '.pulse-commissions-v2-notice .notice-dismiss', function() {
            $.ajax({
                url: pulseCommissionsAdmin.ajaxurl,
                data: {
                    action: 'pulse_commissions_dismiss_v2_notice',
                    nonce: pulseCommissionsAdmin.nonce
                }
            });
        });

        // Clear cache button
        $('#pulse-clear-cache').on('click', function() {
            var $button = $(this);
            var $status = $('#cache-status');
            
            $button.prop('disabled', true);
            $status.text('Clearing cache...');
            
            $.ajax({
                url: pulseCommissionsAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pulse_commissions_clear_cache',
                    nonce: pulseCommissionsAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.text(response.data.message);
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        $status.text('Error clearing cache');
                        $button.prop('disabled', false);
                    }
                },
                error: function() {
                    $status.text('Error clearing cache');
                    $button.prop('disabled', false);
                }
            });
        });
    });
})(jQuery);