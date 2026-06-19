/**
 * tag groups panel
 *
 * @module package/quiqqer/tags/bin/groups/Panel
 * @author www.pcsg.de (Henning Leutz)
 *
 * @require qui/QUI
 * @require qui/controls/desktop/Panel
 * @require qui/controls/buttons/Button
 * @require qui/controls/windows/Confirm
 * @require controls/grid/Grid
 * @require controls/projects/Select
 * @require Locale
 * @require Ajax
 * @require Mustache
 * @require Projects
 * @require package/quiqqer/tags/bin/groups/Group
 * @require text!package/quiqqer/tags/bin/groups/Panel.createGroup.html
 * @require css!package/quiqqer/tags/bin/groups/Panel.css
 */
define('package/quiqqer/tags/bin/groups/Panel', [

    'qui/QUI',
    'qui/controls/desktop/Panel',
    'qui/controls/buttons/Button',
    'qui/controls/windows/Confirm',
    'controls/grid/Grid',
    'controls/projects/Select',
    'Locale',
    'Ajax',
    'Mustache',
    'Projects',
    'package/quiqqer/tags/bin/groups/Group',
    'package/quiqqer/tags/bin/utils/Window',

    'text!package/quiqqer/tags/bin/groups/Panel.createGroup.html',
    'css!package/quiqqer/tags/bin/groups/Panel.css'

], function (QUI, QUIPanel, QUIButton, QUIConfirm, Grid, ProjectSelect,
             QUILocale, QUIAjax, Mustache, Projects, Group, WindowUtils, templateCreateGroup) {
    "use strict";

    var lg = 'quiqqer/tags';

    return new Class({

        Extends: QUIPanel,
        Type   : 'package/quiqqer/tags/bin/groups/Panel',

        Binds: [
            'refresh',
            '$onCreate',
            '$onResize',
            '$onInject',
            'dataRefresh',
            'openCreateGroupDialog',
            '$openDeleteDialog'
        ],

        options: {
            sortOn : false,
            sortBy : false,
            perPage: 150,
            page   : false
        },

        initialize: function (options) {
            this.setAttributes({
                title: QUILocale.get(lg, 'tag.groups.panel.title')
            });

            this.parent(options);

            this.$Grid    = null;
            this.$Project = null;

            this.$Projects = new ProjectSelect({
                emptyselect: false,
                project    : Projects.getName(),
                lang       : Projects.getLang(),
                events     : {
                    onChange: function (value) {
                        this.$setValue(value);
                        this.dataRefresh();
                    }.bind(this),
                    onLoad  : function (Select) {
                        this.$setValue(Select.getValue());
                        this.dataRefresh().then(function () {
                            this.Loader.hide();
                        }.bind(this));
                    }.bind(this)
                }
            });

            this.addEvents({
                onCreate: this.$onCreate,
                onResize: this.$onResize,
                onInject: this.$onInject
            });
        },

        /**
         * Set internal project select value
         *
         * @param {string} selectValue
         */
        $setValue: function (selectValue) {
            if (!selectValue) {
                this.$Project = null;
            } else {
                var values    = selectValue.split(',');
                this.$Project = Projects.get(values[0], values[1]);
            }

            this.$toggleAddButton();
        },

        /**
         * Enable / disable the "add" button depending on whether a
         * project is selected. Safe to call before the button exists.
         */
        $toggleAddButton: function () {
            var AddButton = this.getButtons('add');

            if (!AddButton) {
                return;
            }

            if (this.$Project) {
                AddButton.enable();
            } else {
                AddButton.disable();
            }
        },

        /**
         * Resize the panel
         *
         * @return {Promise}
         */
        $onResize: function () {
            var Content  = this.getContent(),
                size     = Content.getSize(),
                computed = Content.getComputedSize();

            var paddingX = computed['padding-left'] + computed['padding-right'],
                paddingY = computed['padding-top'] + computed['padding-bottom'];

            return Promise.all([
                this.$Grid.setHeight(size.y - paddingY),
                this.$Grid.setWidth(size.x - paddingX)
            ]);
        },

        /**
         * event : on create
         */
        $onCreate: function () {
            var self = this;

            this.addButton(this.$Projects);

            this.addButton({
                type: 'separator'
            });

            // buttons
            this.addButton({
                name     : 'add',
                text     : QUILocale.get('quiqqer/core', 'add'),
                textimage: 'fa fa-plus',
                disabled : true,
                events   : {
                    onClick: this.openCreateGroupDialog
                }
            });

            this.addButton({
                name     : 'edit',
                text     : QUILocale.get('quiqqer/core', 'edit'),
                textimage: 'fa fa-edit',
                disabled : true,
                events   : {
                    onClick: function () {
                        self.openGroup(
                            self.$Project,
                            self.$Grid.getSelectedData()[0].id
                        );
                    }
                }
            });

            this.addButton({
                type: 'separator'
            });

            this.addButton({
                name     : 'delete',
                text     : QUILocale.get('quiqqer/core', 'delete'),
                textimage: 'fa fa-trash',
                disabled : true,
                events   : {
                    onClick: self.$openDeleteDialog
                }
            });

            // the project select's onLoad may have fired before the
            // buttons existed, so sync the add button state now
            this.$toggleAddButton();
        },

        /**
         * event : on inject
         */
        $onInject: function () {
            this.Loader.show();

            var GridContainer = new Element('div', {
                style: {
                    'float': 'left',
                    height : '100%',
                    width  : '100%'
                }
            }).inject(this.getContent());

            this.$Grid = new Grid(GridContainer, {
                pagination       : true,
                multipleSelection: true,
                perPage          : this.getAttribute('perPage'),
                page             : this.getAttribute('page'),
                sortOn           : this.getAttribute('sortOn'),
                serverSort       : true,
                columnModel      : [{
                    header   : QUILocale.get('quiqqer/core', 'id'),
                    dataIndex: 'id',
                    dataType : 'number',
                    width    : 50
                }, {
                    header   : QUILocale.get('quiqqer/core', 'title'),
                    dataIndex: 'title',
                    dataType : 'text',
                    width    : 200
                }, {
                    header   : QUILocale.get('quiqqer/core', 'workingtitle'),
                    dataIndex: 'workingtitle',
                    dataType : 'text',
                    width    : 200
                }, {
                    header   : QUILocale.get('quiqqer/core', 'description'),
                    dataIndex: 'desc',
                    dataType : 'text',
                    width    : 250
                }, {
                    header   : QUILocale.get('quiqqer/core', 'priority'),
                    dataIndex: 'priority',
                    dataType : 'number',
                    width    : 100
                }, {
                    header   : QUILocale.get(lg, 'tag.groups.grid.generate'),
                    dataIndex: 'generatedIcon',
                    dataType : 'node',
                    width    : 60
                }]
            });

            this.$Grid.addEvents({
                onRefresh: this.dataRefresh,

                onDblClick: function () {
                    this.openGroup(
                        this.$Project,
                        this.$Grid.getSelectedData()[0].id
                    );
                }.bind(this),

                onClick: function () {
                    var selected = this.$Grid.getSelectedData();

                    if (selected.length < 2) {
                        this.getButtons('edit').enable();
                    } else {
                        this.getButtons('edit').disable();
                    }

                    this.getButtons('delete').enable();

                    this.fireEvent('click', [this, this.$Grid.getSelectedData()]);
                }.bind(this)
            });

            this.$Grid.refresh();
        },

        /**
         * Refresh the data
         *
         * @return {Promise}
         */
        dataRefresh: function () {
            if (!this.$Grid || !this.$Project) {
                return Promise.resolve();
            }

            this.getButtons('edit').disable();
            this.getButtons('delete').disable();

            this.Loader.show();

            var options = this.$Grid.options || {};

            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_tags_ajax_groups_list', function (result) {

                    var iconOk = new Element('span', {
                        'class': 'fa fa-check'
                    });

                    var iconFalse = new Element('span', {
                        'class': 'fa fa-remove'
                    });

                    for (var i = 0, len = result.data.length; i < len; i++) {
                        result.data[i].generatedIcon = result.data[i].generated ? iconOk.clone() : iconFalse.clone();
                    }

                    this.$Grid.setData(result);
                    this.Loader.hide();

                    resolve();

                }.bind(this), {
                    'package': 'quiqqer/tags',
                    onError  : reject,
                    project  : this.$Project.encode(),
                    params   : JSON.encode({
                        sheet : options.page,
                        limit : options.perPage,
                        sortOn: options.sortOn,
                        sortBy: options.sortBy
                    })
                });
            }.bind(this));
        },

        /**
         * Opens the tag group create dialog
         */
        openCreateGroupDialog: function () {
            var self = this;

            if (!self.$Project) {
                return;
            }

            new QUIConfirm({
                title    : QUILocale.get(lg, 'tag.groups.window.create.title'),
                icon     : 'fa fa-tags',
                maxWidth : 450,
                maxHeight: 410,
                autoclose: false,
                events   : {
                    onOpen: function (Win) {
                        Win.Loader.show();

                        var Content = Win.getContent();

                        Content.set('html', Mustache.render(templateCreateGroup, {
                            title      : QUILocale.get('quiqqer/core', 'title'),
                            image      : QUILocale.get('quiqqer/core', 'image'),
                            description: QUILocale.get('quiqqer/core', 'description'),
                            titleError : QUILocale.get(lg, 'panel.add.window.error.title.empty'),
                            keepOpen   : QUILocale.get(lg, 'panel.add.window.keepOpen.group')
                        }));

                        var Title = Content.getElement('[name="title"]'),
                            Img   = Content.getElement('[name="image"]'),
                            Desc  = Content.getElement('[name="desc"]');

                        WindowUtils.bindSubmitShortcuts(Win, [Title, Img], Desc);
                        WindowUtils.injectShortcutHint(Content);

                        Title.addEventListener('input', function () {
                            if (Title.value.trim() !== '') {
                                WindowUtils.hideFieldError(
                                    Content.querySelector('[data-name="title-error"]'),
                                    Title
                                );
                            }
                        });

                        QUI.parse(Content).then(function () {

                            Title.focus();

                            return new Promise(function (resolve) {
                                require(['utils/Controls'], function (Utils) {
                                    Utils.parse(Content).then(resolve);
                                });
                            });
                        }).then(function () {
                            Win.Loader.hide();
                        });
                    },

                    onSubmit: function (Win) {
                        var Content    = Win.getContent(),
                            Title      = Content.getElement('[name="title"]'),
                            TitleError = Content.querySelector('[data-name="title-error"]');

                        if (Title.value.trim() === '') {
                            WindowUtils.showFieldError(TitleError, Title);
                            return;
                        }

                        WindowUtils.hideFieldError(TitleError, Title);

                        Win.Loader.show();

                        var Img      = Content.getElement('[name="image"]'),
                            Desc     = Content.getElement('[name="desc"]'),
                            KeepOpen = Content.querySelector('[data-name="keep-open"]');

                        QUIAjax.post('package_quiqqer_tags_ajax_groups_create', function () {
                            self.dataRefresh();

                            if (KeepOpen && KeepOpen.checked) {
                                Title.value = '';
                                Desc.value  = '';
                                Img.value   = '';

                                var quiid        = Img.getParent().get('data-quiid');
                                var ImageControl = QUI.Controls.getById(quiid);

                                if (ImageControl) {
                                    ImageControl.setValue('');
                                }

                                WindowUtils.hideFieldError(TitleError, Title);
                                Win.Loader.hide();
                                Title.focus();
                                return;
                            }

                            Win.close();
                        }, {
                            'package': 'quiqqer/tags',
                            project  : self.$Project.encode(),
                            title    : Title.value,
                            image    : Img.value,
                            desc     : Desc.value
                        });
                    }
                }
            }).open();
        },

        /**
         * Opens the group
         *
         * @param {Object} Project - Project object
         * @param {number} groupId - id of the tag group
         */
        openGroup: function (Project, groupId) {
            new Group({
                projectName: Project.getName(),
                projectLang: Project.getLang(),
                groupId    : groupId
            }).inject(this.getParent());
        },

        /**
         * Opens the delete group(s) dialog
         */
        $openDeleteDialog: function () {
            var self         = this;
            var selectedRows = self.$Grid.getSelectedData();
            var groupIds     = [];
            var groupTitles  = [];

            for (var i = 0, len = selectedRows.length; i < len; i++) {
                groupIds.push(selectedRows[i].id);
                groupTitles.push(selectedRows[i].title);
            }

            // open popup
            var Popup = new QUIConfirm({
                title      : QUILocale.get(
                    lg, 'tag.groups.window.delete.title'
                ),
                maxHeight  : 300,
                maxWidth   : 500,
                closeButton: true,
                content    : false,
                events     : {
                    onOpen  : function () {
                        Popup.getContent().set(
                            'html',
                            QUILocale.get(lg, 'tag.groups.window.delete.info', {
                                groups: groupTitles.join('<br>')
                            })
                        );
                    },
                    onSubmit: function () {
                        Popup.Loader.show();

                        self.deleteGroups(groupIds).then(function (success) {
                            Popup.Loader.hide();

                            if (!success) {
                                return;
                            }

                            Popup.close();
                            self.dataRefresh();
                        });
                    }
                }
            });

            Popup.open();
        },

        /**
         * Delete one or more groups
         *
         * @param {Array} groupIds
         * @return {Promise}
         */
        deleteGroups: function (groupIds) {
            var self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.post(
                    'package_quiqqer_tags_ajax_groups_delete',
                    resolve, {
                        'package': 'quiqqer/tags',
                        project  : self.$Project.encode(),
                        groupIds : JSON.encode(groupIds),
                        onError  : reject
                    }
                );
            });


        }
    });
});
