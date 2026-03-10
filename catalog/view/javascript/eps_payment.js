$(document).ready(function() {
    console.log("🟢 EPS Global Script Loaded via Extension File!");

    $(document).off('change click', '.eps-radio-trigger').on('change click', '.eps-radio-trigger', function(e) {
        e.stopPropagation(); 
        var selectedType = $('input[name="eps_payment_type"]:checked').val();
        
        if (selectedType === 'emi') {
            $('#eps-emi-container').slideDown();
            loadEpsBanks();
        } else {
            $('#eps-emi-container').slideUp();
        }
    });

    function loadEpsBanks() {
        $.ajax({
            url: 'index.php?route=extension/payment/eps/getBanks',
            dataType: 'json',
            beforeSend: function() {
                $('#eps_bank_id').html('<option value="">Loading banks...</option>').prop('disabled', true);
            },
            success: function(json) {
                if (json['Banks']) {
                    var html = '<option value="">-- Select Bank --</option>';
                    for (var i = 0; i < json['Banks'].length; i++) {
                        html += '<option value="' + json['Banks'][i]['BankId'] + '">' + json['Banks'][i]['BankName'] + '</option>';
                    }
                    $('#eps_bank_id').html(html).prop('disabled', false);
                } else {
                    $('#eps_bank_id').html('<option value="">Error loading banks</option>');
                }
            },
            error: function(xhr) {
                $('#eps_bank_id').html('<option value="">Error connecting to API</option>');
            }
        });
    }

    $(document).off('change', '#eps_bank_id').on('change', '#eps_bank_id', function() {
        var bank_id = $(this).val();
        
        if (bank_id) {
            $.ajax({
                url: 'index.php?route=extension/payment/eps/getEmiDetails',
                type: 'post',
                data: { bank_id: bank_id },
                dataType: 'json',
                beforeSend: function() {
                    $('#eps-tenure-group').slideDown();
                    $('#eps_emi_month_id').html('<option value="">Loading tenures...</option>').prop('disabled', true);
                },
                success: function(json) {
                    if (json['EmiDetails']) {
                        var html = '<option value="">-- Select Tenure --</option>';
                        for (var i = 0; i < json['EmiDetails'].length; i++) {
                            var monthStr = json['EmiDetails'][i]['EMIMonth'];
                            var monthInt = monthStr.match(/\d+/);
                            monthInt = monthInt ? monthInt[0] : '';
                            html += '<option value="' + monthInt + '">' + monthStr + ' - ' + json['EmiDetails'][i]['EMIMonthlySettlement'] + '</option>';
                        }
                        $('#eps_emi_month_id').html(html).prop('disabled', false);
                    } else {
                        $('#eps_emi_month_id').html('<option value="">No tenures available</option>');
                    }
                }
            });
        } else {
            $('#eps-tenure-group').slideUp();
        }
    });

    $(document).off('click', '#button-confirm-eps').on('click', '#button-confirm-eps', function(e) {
        e.preventDefault();
        
        var payment_type = $('input[name="eps_payment_type"]:checked').val();
        var data = { payment_type: payment_type };

        if (payment_type === 'emi') {
            data.bank_id = $('#eps_bank_id').val();
            data.emi_month_id = $('#eps_emi_month_id').val();

            if (!data.bank_id || !data.emi_month_id) {
                alert('Please select both a Bank and an EMI Tenure to proceed.');
                return false;
            }
        }

        $.ajax({
            url: 'index.php?route=extension/payment/eps/confirm',
            type: 'post',
            data: data,
            dataType: 'json',
            beforeSend: function() {
                $('#button-confirm-eps').button('loading');
            },
            complete: function() {
                $('#button-confirm-eps').button('reset');
            },
            success: function(json) {
                if (json['redirect']) {
                    location = json['redirect'];
                } else if (json['error']) {
                    alert(json['error']);
                }
            },
            error: function(xhr) {
                alert("Error initiating payment. Please try again.");
            }
        });
    });
});