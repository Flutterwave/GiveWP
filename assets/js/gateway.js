(() => {
    let settings = {};

    // Render gateway fields
    function FlutterwaveGiveWPFields() {
        return window.wp.element.createElement(
            "div",
            {
                className: 'flutterwave-givewp-help-text'
            },
            window.wp.element.createElement(
                "p",
                {
                    style: {marginBottom: 0}
                },
                settings.message,
            )
        );
    }

    // Gateway object
    const FlutterwaveGiveWPGateway = {
        id: "flutterwave",
        initialize() {
            settings = this.settings
        },
        Fields() {
            return window.wp.element.createElement(FlutterwaveGiveWPFields);
        },
    };

    // Register the gateway
    window.givewp.gateways.register(FlutterwaveGiveWPGateway);
})();