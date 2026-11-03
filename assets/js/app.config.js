/* global app */
(function app_tip(config, $)
{
    var _config;

    function load()
    {
        var config = {};
        // storage can be missing, full or refused (private windows): settings then last the page
        try {
            var stored = window.localStorage.getItem('app.config');
            if (stored) {
                config = JSON.parse(stored);
            }
        } catch (e) {
            config = {};
        }
        return _.extend({
            'show-all-sets': false,
            'show-only-deck': false,
            'show-errata': true,
            'hide-banned': false
        }, config || {});
    }

    function save(config)
    {
        try {
            window.localStorage.setItem('app.config', JSON.stringify(config));
        } catch (e) {
            // kept in memory for this page only
        }
    }

    function getConfig()
    {
        if (! _config) {
            _config = load();
        }
        return _config;
    }

    config.set = function (name, value) {
        var config = getConfig();
        config[name] = value;
        save(config);
    };

    config.get = function ( name) {
        var config = getConfig();
        return config[name];
    }


})(app.config = {}, jQuery);
