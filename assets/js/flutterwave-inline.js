(function($){
    $(document).on('give_gateway_loaded_flutterwave', function(event, settings){
        // Triggered when Give form selects Flutterwave
        const form = settings.form;
        form.on('submit', function(e){
            e.preventDefault();
            const amount = form.find('input[name="give-amount"]').val();
            const email = form.find('input[name="give-email"]').val();

            FlutterwaveCheckout({
                key: settings.publicKey,
                email,
                amount: parseFloat(amount) * 100, // Convert to kobo
                currency: settings.currency,
                callback: function(response){
                    // Post token to backend for finalization
                    $.post(settings.ajaxUrl, { token: response.reference }, function(res){
                        if(res?.redirect){
                            window.location = res.redirect;
                        } else {
                            alert('Error completing payment');
                        }
                    });
                },
                onClose: function(){
                    alert('Payment window closed');
                }
            });
        });
    });
})(jQuery);