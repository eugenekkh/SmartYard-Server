({
    init: function () {
        // submodule - module<dot>submodule
        moduleLoaded("addresses.subscriberInbox", this);
    },

    sendMessage: function (subscriberId) {

    },

    renderSubscriberInbox: function (subscriberId) {
        loadingStart();

        GET("inbox", "messages", subscriberId, false).
        fail(FAILPAGE).
        done(response => {
            console.log(response);

            cardTable({
                target: "#mainForm",
                title: {
                    caption: i18n("addresses.messages"),
                    button: {
                        icon: "fas fa-fw fa-envelope-open",
                        caption: i18n("addresses.messageSend"),
                        click: () => {
                            modules.addresses.subscriberInbox.sendMessage(subscriberId);
                        },
                    },
                },
                columns: [
                    {
                        title: i18n("addresses.messageId"),
                    },
                    {
                        title: i18n("addresses.messageMobile"),
                    },
                    {
                        title: i18n("addresses.messageTitle"),
                    },
                    {
                        title: i18n("addresses.messageBody"),
                        nowrap: true,
                        fullWidth: true,
                    },
                    {
                        title: i18n("addresses.messageReaded"),
                    },
                    {
                        title: i18n("addresses.messagesCode"),
                    },
                ],
                rows: () => {
                    let rows = [];
                    /*
                                    let list = [];

                                    rows.push({
                                        uid: list[i].subscriberId,
                                        cols: [
                                            {
                                                data: list[i].subscriberId,
                                            },
                                            {
                                                data: list[i].mobile,
                                            },
                                            {
                                                data: owner?i18n("yes"):i18n("no"),
                                            },
                                        ],
                                    });
                    */
                    return rows;
                }

            }).show();
        }).
        always(loadingDone);
    },

    route: function (params) {
        modules.addresses.topMenu();

        modules.addresses.subscriberInbox.renderSubscriberInbox(params.subscriberId);
    }
}).init();
