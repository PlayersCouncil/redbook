(function app_data(data, $) {

    data.isLoaded = false;

    var database_changed = false;
    var locale_changed = false;

    var fdb = new ForerunnerDB();
    // "redbook2": sets gained their block (cycle_code), so browsers drop the older copy
    var database = fdb.db('redbook2');

    data.db = database;

    var masters = {
        packs: database.collection('master_pack', {primaryKey: 'code'}),
        cards: database.collection('master_card', {primaryKey: 'code'}),
        formats: database.collection('master_format', {primaryKey: 'code'})
    };

    var dfd;

    /**
     * A Gemp format as Red Book copied it (phase3/04)
     * @param {String} code
     * @return {Object|null}
     */
    data.findFormat = function findFormat(code) {
        return data.formats ? data.formats.findById(code) || null : null;
    };

    /** The formats a deck can target, in Gemp's order */
    data.selectableFormats = function selectableFormats() {
        return data.formats.find({hall: true, retired: false}, {$orderBy: {position: 1}});
    };

    /** banned, restricted, limit2, limit3 or null for a card in a format */
    data.format_limit = function format_limit(format, code) {
        var lists = ['banned', 'restricted', 'limit2', 'limit3'];
        for(var i = 0; i < lists.length; i++) {
            if((format[lists[i]] || []).indexOf(code) !== -1) {
                return lists[i];
            }
        }
        return null;
    };

    // Ketura's culture icons (public/images/cultures), named by culture code
    var CULTURE_FILES = {uruk_hai: 'uruk'};

    /** The culture's icon, for a CSS mask so it takes the text colour; null for none */
    data.cultureIcon = function cultureIcon(code) {
        if(!code || code === 'the_one_ring') {
            return null;
        }
        return '/images/cultures/' + (CULTURE_FILES[code] || code) + '.svg';
    };

    /** A span showing a culture's icon in the current text colour */
    data.cultureIconHtml = function cultureIconHtml(code, name) {
        var url = data.cultureIcon(code);
        if(!url) {
            return '';
        }
        return '<span class="culture-icon" role="img" aria-label="' + _.escape(name || code) + '" title="' + _.escape(name || code)
            + '" style="--icon:url(' + url + ')"></span>';
    };

    function onCollectionUpdate(updated) {
        database_changed = true;
    }

    function onCollectionInsert(inserted, failed) {
        database_changed = true;
    }

    /**
     * loads the database from local
     * sets up a Promise on all data loading/updating
     * @memberOf data
     */
    function load() {
        masters.formats.load(function (err) {
            if (err) {
                console.log('error when loading formats', err);
            }
            masters.packs.load(function (err) {
                if (err) {
                    console.log('error when loading packs', err);
                }
                masters.cards.load(function (err) {
                    if (err) {
                        console.log('error when loading cards', err);
                    }

                    /*
                     * data has been fetched from local store
                     */

                    /*
                     * we set up insert and update listeners now
                     * if we did it before, .load() would have called onInsert
                     */
                    masters.packs.on("insert", onCollectionInsert).on("update", onCollectionUpdate);
                    masters.cards.on("insert", onCollectionInsert).on("update", onCollectionUpdate);
                    masters.formats.on("insert", onCollectionInsert).on("update", onCollectionUpdate);

                    /*
                     * if database is not empty, use it for now
                     */
                    if (masters.packs.count() > 0 && masters.cards.count() > 0) {
                        release();
                    }

                    /*
                     * then we ask the server if new data is available
                     */
                    query();
                });
            });
        });
    }

    /**
     * release the data for consumption by other modules
     * @memberOf data
     */
    function release() {
        data.formats = database.collection('format', {primaryKey: 'code', changeTimestamp: false});
        data.formats.setData(masters.formats.find());

        data.packs = database.collection('pack', {primaryKey: 'code', changeTimestamp: false});
        data.packs.setData(masters.packs.find());

        data.cards = database.collection('card', {primaryKey: 'code', changeTimestamp: false});
        data.cards.setData(masters.cards.find());

        data.isLoaded = true;

        $(document).trigger('data.app');
    }

    /**
     * queries the server to update data
     * @memberOf data
     */
    function query() {
        dfd = {
            formats: new $.Deferred(),
            packs: new $.Deferred(),
            cards: new $.Deferred()
        };
        $.when(dfd.formats, dfd.packs, dfd.cards).done(update_done).fail(update_fail);

        $.ajax({
            url: Routing.generate('api_formats'),
            success: parse_formats,
            error: function (jqXHR, textStatus, errorThrown) {
                console.log('error when requesting formats', errorThrown);
                dfd.formats.reject(false);
            }
        });

        $.ajax({
            // v=2: a new URL so a browser's cached copy (without blocks) isn't reused
            url: Routing.generate('api_packs', {v: 2}),
            success: parse_packs,
            error: function (jqXHR, textStatus, errorThrown) {
                console.log('error when requesting packs', errorThrown);
                dfd.packs.reject(false);
            }
        });

        $.ajax({
            url: Routing.generate('api_cards', {'v': '2.0'}),
            success: parse_cards,
            error: function (jqXHR, textStatus, errorThrown) {
                console.log('error when requesting cards', errorThrown);
                dfd.cards.reject(false);
            }
        });
    }

    /**
     * called if all operations (load+update) succeed (resolve)
     * deferred returns true if data has been updated
     * @memberOf data
     */
    function update_done() {
        if (database_changed && !locale_changed) {
            /*
             * we display a message informing the user that they can reload their page to use the updated data
             * except if we are on the front page, because data is not essential on the front page
             */
            if ($('.site-title').size() === 0) {
                var message = "A new version of the data is available. Click <a href=\"javascript:window.location.reload(true)\">here</a> to reload your page.";
                app.ui.insert_alert_message('warning', message);
            }
        }

        // if it is a force update, we haven't release the data yet
        if (!data.isLoaded) {
            release();
        }
    }

    /**
     * called if an operation (load+update) fails (reject)
     * deferred returns true if data has been loaded
     * @memberOf data
     */
    function update_fail(formats_loaded, packs_loaded, cards_loaded) {
        if (formats_loaded === false || packs_loaded === false || cards_loaded === false) {
            var message = Translator.trans('data_load_fail');
            app.ui.insert_alert_message('danger', message);
        } else {
            /*
             * since data hasn't been persisted, we will have to do the query next time as well
             * -- not much we can do about it
             * but since data has been loaded, we call the promise
             */
            release();
        }


    }

    /**
     * updates the database if necessary, from fetched data
     * @memberOf data
     */
    function update_collection(data, collection, locale, deferred) {
        // we update the database and Forerunner will tell us if the data is actually different
        data.forEach(function (row) {
            var existingRow = collection.findById(row.code);
            if(existingRow) {
                // check last-updated timestamp here, if applicable, before updating.
                // only update the record if the timestamp differs.
                if (row.dateUpdate && row.dateUpdate !== existingRow.dateUpdate) {
                    collection.update({code: row.code}, row);
                }
            } else {
                collection.insert(row);
            }
        });

        // delete rows that aren't in the payload
        var codes = data.map(function(value) {
            return value.code;
        });
        collection.remove({ code: { $nin: codes } });

        // we update the locale
        if (locale !== collection.metaData().locale) {
            locale_changed = true;
        }
        collection.metaData().locale = locale;

        collection.save(function (err) {
            if (err) {
                console.log('error when saving ' + collection.name(), err);
                deferred.reject(true)
            } else {
                deferred.resolve();
            }
        });
    }

    /**
     * handles the response to the ajax query for the formats
     * @memberOf data
     */
    function parse_formats(response, textStatus, jqXHR) {
        _.each(response, function (row) { row.dateUpdate = row.date_update; });
        update_collection(response, masters.formats, jqXHR.getResponseHeader('Content-Language'), dfd.formats);
    }

    /**
     * handles the response to the ajax query for packs data
     * @memberOf data
     */
    function parse_packs(response, textStatus, jqXHR) {
        var locale = jqXHR.getResponseHeader('Content-Language');
        update_collection(response, masters.packs, locale, dfd.packs);
    }

    /**
     * handles the response to the ajax query for the cards data
     * @memberOf data
     */
    function parse_cards(response, textStatus, jqXHR) {
        var locale = jqXHR.getResponseHeader('Content-Language');
        update_collection(response, masters.cards, locale, dfd.cards);
    }

    $(function () {
        load();
    });

})(app.data = {}, jQuery);
