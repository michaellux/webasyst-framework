<?php

/*
 * This file is part of Webasyst framework.
 *
 * Licensed under the terms of the GNU Lesser General Public License (LGPL).
 * http://www.webasyst.com/framework/license/
 *
 * @link http://www.webasyst.com/
 * @author Webasyst LLC
 * @copyright 2011 Webasyst LLC
 * @package wa-system
 * @subpackage config
 */

/**
 * An interface between application and contacts app to allow access rights management.
 *
 * To allow custom access configuration for an app, add a 'rights' => true to /lib/confid/app.php
 * Then create /lib/appnameRightsConfig.class.php with appnameRightConfig class that extends waRightConfig.
 * Instance of this class will be used to get HTML for access control form for current app.
 *
 * Though the HTML form can be thoroughly generated by app (by overriding getHTML), normally it is not needed.
 * Class has a templating system to add controls commonly used in access control forms. To use this feature,
 * override init() and use addItem() to add controls to the form. The default implementation if getHTML()
 * will build the form for you. If you need some custom controls, you can override getItemHTML()
 * to generate them.
 *
 * Normally system keeps access_key => value pairs in a centralized storage. Application may choose
 * to implement its own storage for all or some of the access keys. getRights() and setRights() are hooks
 * that get called when admin manages application access for contact or group. This allows application
 * to control whether given key => value pair to be stored by system or by application.
 */
abstract class waRightConfig
{
    protected $app;
    protected $items = array();

    public function __construct()
    {
        $this->app = substr(get_class($this), 0, -11);
        $this->init();
    }

    /**
     * Override in subclass to initialize access keys. See addItem()
     */
    public function init()
    {
        // Override me!
    }

    /**
     * Adds one control to the form that the default implementation of getHTML will return.
     *
     * Type: checkbox
     * - cssclass: CSS class for <tr>
     *
     * Type: list - list of checkboxes with $label being a header above them.
     * - cssclass: CSS class for <tr>
     * - items: array(access_key => human readable name) - checkboxes to show in the list.
     * - hint1: string to show above left checkbox col;
     *          'all_checkbox' will show a checkbox to check everything at once, and its status
     *          will be saved with access_key $name.all
     * - hint2: string to show above right checkbox col, if it's present
     *
     * @param string $name access_key to store in DB
     * @param string $label human readable name for a field
     * @param string $type control type; currently checkbox|list
     * @param array $params parameters passed to getItemHTML
     */
    public function addItem($name, $label, $type = 'checkbox', $params = array())
    {
        $this->items[] = array(
            'name' => $name,
            'label' => _wd($this->app, $label),
            'type' => $type,
            'params' => $params
        );
    }

    /**
     * List of controls added via $this->addItem().
     */
    public function getItems()
    {
        return $this->items;
    }

    /**
     * Return custom access rights managed by app for contact id (not considering group he's in) or a set of group ids.
     * Application must override this if it uses custom access rights storage.
     *
     * @param int|array $contact_id contact_id (positive) or a list of group_ids (positive)
     * @return array access_key => value; for group_ids aggregate status is returned, as if for a member of all groups.
     */
    public function getRights($contact_id)
    {
        return array();
    }

    /**
     * Update custom rights storage for given contact and access_key setting given value.
     *
     * @param int $contact_id contact_id (if positive) or group id (if negative)
     * @param string $right access_key to set value for
     * @param mixed $value value to save
     * @return boolean false to write this key and value to system storage; true if application chooses to keep it in its own place.
     */
    public function setRights($contact_id, $right, $value = null)
    {
        return false;
    }

    /**
     * Remove all access control data for given contact or group id.
     *
     * @param int $contact_id contact id (if positive) or group id (if negative)
     */
    public function clearRights($contact_id)
    {
        // Nothing to do
    }

    /**
     * Set default access for given contact and return access rights to set up in system access storage.
     * Called after contact has been granted Limited access to the app.
     *
     * @param int $contact_id
     * @return array access key => value
     */
    public function setDefaultRights($contact_id)
    {
        return $this->getDefaultRights($contact_id);
    }

    /**
     * Return access rights to set in access control dialog by default
     * for a contact without any rights yet.
     *
     * @param int $contact_id
     * @return array access key => value
     */
    public function getDefaultRights($contact_id)
    {
        return array();
    }

    /**
     * Return HTML to include into page to customize user access for application.
     *
     * @param array $rights access_key => value for both system-managed and app-managed rights
     * @param array $inherited access_key => value for rights inherited from groups member is in. Default is null: do not show group UI at all (e.g. when  managing group access)
     * @return string - generated HTML
     * @throws waException
     */
    public function getHTML($rights = array(), $inherited=null)
    {
        // UI 2.0 branch
        if (wa()->whichUI($this->app) == '2.0') {
            return $this->getUI20HTML($rights, $inherited);
        }

        if ($inherited !== null) {
            $html = '<table class="zebra c-access-app"><tr><th></th>'.
                '<th width="1%">'._ws('Effective<br/>rights').'</th>'.
                '<th width="1%">'._ws('Granted<br/>personally').'</th>'.
                '<th width="1%">'._ws('Inherited<br/>from groups').'</th></tr>';
        } else {
            $html = '<table class="zebra c-access-app c-access-app-group">';
            //$html .= '<tr><th width="50%"></th><th width="50%">'._ws('Group').'</th></tr>';
        }
        $addScriptForCB = FALSE;
        $addScriptForSelect = FALSE;
        foreach ($this->items as $item) {
            $html .= $this->getItemHTML($item['name'], $item['label'], $item['type'], $item['params'], $rights, $inherited);
            if ($item['type'] == 'list' && isset($item['params']['hint1']) && $item['params']['hint1'] == 'all_checkbox') {
                $addScriptForCB = TRUE;
            }

            if ($item['type'] == 'selectlist' && isset($item['params']['hint1']) && $item['params']['hint1'] == 'all_select') {
                $addScriptForCB = TRUE;
            }

            if ($inherited !== null && ($item['type'] == 'select' || $item['type'] == 'selectlist')) {
                $addScriptForSelect = TRUE;
            }
        }
        $html .= '</table>';

        $html .= '
            <script>(function() {
                // Make indicators change when user changes personal access
                var updateIndicator = function() {
                    var self = $(this),
                        tr = self.parents("table.c-access-app tr"),
                        changed = false;
                    if (tr.find("input[type=\"checkbox\"]:checked").size() > 0) {
                        changed = tr.find("i.icon10.no").removeClass("no").addClass("yes").length > 0;
                    } else {
                        changed = tr.find("i.icon10.yes").removeClass("yes").addClass("no").length > 0;
                    }
                    if (changed) {
                        self.parents("form").trigger("wa.change");
                    }
                };
                $("table.c-access-app input[type=\"checkbox\"]:enabled").click(updateIndicator);';

        if ($addScriptForSelect) {
            $html .= '
                // Change resulting column for selects
                var updateIndicatorForSelect = function() {
                    var self = $(this);
                    var tr = self.closest("table.c-access-app tr");
                    var group_value = tr.find("input.g-value").val()-0;
                    var personal_value = self.val()-0;
                    var result = group_value ? Math.max(personal_value, group_value) : personal_value;
                    var name = self.find("option[value=\""+result+"\"]").text();
                    tr.find("strong").text(name);
                };
                $("table.c-access-app select").change(updateIndicatorForSelect);';
        }

        if ($addScriptForCB) {
            $html .= <<<HTML
                // Logic for "all" checkboxes
                /** if $(this) is checked, then check and disable all checkboxes starting with the same name (minus `.all`)
                  * if not checked, then enable all those checkboxes. */
                var handler = function() {
                    var cb = $(this);
                    cb.parents("table.c-access-app")
                        .find("input[type=\"checkbox\"][name^=\""+cb.attr("name").replace(/\.all]/,"")+"\"]")
                        .each(function(k,cb2) {
                            cb2 = $(cb2);
                            if (cb.attr("checked")) {
                                cb2.attr("checked", true).attr("disabled", true);
                                updateIndicator.call(cb2[0]);
                            } else {
                                cb2.attr("checked", false).attr("disabled", false);
                                updateIndicator.call(cb2[0]);
                            }
                        });
                    cb.attr("disabled", false);
                    if (cb.val() !== "") {
                    cb.parents("table.c-access-app")
                        .find("select[name^=\""+cb.attr("name").replace(/\.all]/,"")+"\"]").each(function (k,cb2) {
                            cb2 = $(cb2);
                            cb2.val(cb.val());
                            updateIndicatorForSelect.call(cb2[0]);
                        });
                    }
                };
                /* For each enabled "all" checkbox in a table.c-access-app:
                   - Add an onclick handler
                   - Call the handler initially, if `all` is checked. */
                $("table.c-access-app .c-access-cb-all select").each(function(k,cb) {
                    cb = $(cb).change(handler);
                    //handler.call(cb[0]);
                });
                $("table.c-access-app .c-access-subcontrol-item select").change(function () {
                    var el = $(this);
                    var all = el.closest("table.c-access-app").find(".c-access-cb-all select");
                    if (all.val() !== $(this).val()) {
                        all.val('');
                    }
                });
                $("table.c-access-app .c-access-cb-all input:enabled").each(function(k,cb) {
                    cb = $(cb).click(handler);
                    if (cb.is(":checked")) {
                        handler.call(cb[0]);
                    }
                });
HTML;
        }

        $html .= '
            }).call({});</script>';

        return $html;
    }

    /**
     * Generate HTML for one field that was previously added by addItem().
     * Used by the default implementation of getHTML() to build a form.
     * See addItem() for details
     *
     * @param string $name access_key to store in DB
     * @param string $label human readable name for a field
     * @param string $type control type; currently checkbox|list
     * @param array $params parameters
     * @param array $rights
     * @param array $inherited
     * @return string HTML
     * @throws waException
     */
    protected function getItemHTML($name, $label, $type, $params, $rights, $inherited=null) {

        // UI 2.0 branch
        if (wa()->whichUI($this->app) == '2.0') {
            return $this->getUI20ItemHTML($name, $label, $type, $params, $rights, $inherited);
        }

        $own = isset($rights[$name]) ? $rights[$name] : '';
        $group = $inherited && isset($inherited[$name]) ? $inherited[$name] : null;
        if (!isset($params['cssclass'])) {
            $params['cssclass'] = '';
        }
        switch ($type) {
            case 'select':
                if (!isset($params['options']) || !$params['options']) {
                    return '';
                }
                $own = ifempty($own, 0);
                $group = ifempty($group, 0);
                $max = $group ? max($own, $group) : $own;

                $o = $params['options'];
                $oHTML = array();
                foreach($o as $val => $opt) {
                    $oHTML[] = '<option value="'.$val.'"'.($own==$val ? ' selected' : '').'>'.htmlspecialchars($opt).'</option>';
                }
                $oHTML = implode('', $oHTML);

                // corner case when option of this key (group) not exists
                if (!isset($o[$group])) {
                    $group = key($o);
                }

                return '<tr'.($params['cssclass'] ? ' class="'.$params['cssclass'].'"' : '').'>'.
                            '<td><div>'.$label.'</div></td>'.
                            ($inherited !== null ? '<td><strong>'.$o[$max].'</strong></td>' : '').
                            '<td><input type="hidden" name="app['.$name.']" value="0">'.
                                '<select name="app['.$name.']">'.$oHTML.'</select>'.
                            '</td>'.
                            ($inherited !== null ? '<td>'.($inherited && isset($inherited['backend']) ? $o[$group] : '').'<input type="hidden" class="g-value" value="'.$group.'"></td>' : '').
                        '</tr>';
            case 'checkbox':
                return '<tr'.($params['cssclass'] ? ' class="'.$params['cssclass'].'"' : '').'>'.
                            '<td><div>'.$label.'</div></td>'.
                            ($inherited !== null ? '<td><i class="icon10 '.($own || $group ? 'yes' : 'no').'"></i></td>' : '').
                            '<td><input type="hidden" name="app['.$name.']" value="0"><input type="checkbox" name="app['.$name.']" value="'.(isset($params['value']) ? $params['value'] : 1).'"'.($own ? ' checked="checked"' : '').'></td>'.
                            ($inherited !== null ? '<td><input type="checkbox"'.($group ? ' checked="checked"' : '').' disabled="disabled"></td>' : '').
                        '</tr>';
            case 'always_enabled':
                if ($inherited !== null) {
                    $html = '<td><div>'.$label.'</div></td>'.
                            '<td><i class="icon10 yes"></i></td>'.
                            '<td></td><td></td>';
                } else {
                    $html = '<td><div>'.$label.'</div></td>'.
                            '<td><i class="icon10 yes"></i></td>';
                }
                return '<tr'.($params['cssclass'] ? ' class="'.$params['cssclass'].'"' : '').'>'.$html.'</tr>';
            case 'list':
                $indicator = '';
                if (isset($params['hint1']) && $params['hint1'] == 'all_checkbox') {
                    $own = isset($rights[$name.'.all']) ? $rights[$name.'.all'] : '';
                    $group = $inherited && isset($inherited[$name.'.all']) ? $inherited[$name.'.all'] : null;
                    $params['hint1'] = '<input type="hidden" name="app['.$name.'.all]" value="0"><span class="c-access-cb-all"><label><input type="checkbox" name="app['.$name.'.all]" value="1"'.($own ? ' checked="checked"' : '').'>'._ws('all').'</label></span>';
                    if($inherited !== null) {
                        $params['hint2'] = '<span class="c-access-cb-all"><input type="checkbox"'.($group ? ' checked="checked"' : '').' disabled="disabled">'._ws('all').'</span>';
                        //no indicator on this line anymore
                        //$indicator = '<span class="float-right"><i class="icon10 '.($own || $group ? 'yes' : 'no').'"></i></span>';
                    }
                }
                $html = '<tr class="c-access-subcontrol-header'.($params['cssclass'] ? ' '.$params['cssclass'] : '').'">'.
                                '<td><div>'.$indicator.$label.'</div></td>'.
                                ($inherited !== null ? '<td></td>' : '').
                                '<td><div class="hint">'.(isset($params['hint1']) ? $params['hint1'] : '').'</td>'.
                                ($inherited !== null ? '<td><div class="hint">'.(isset($params['hint2']) ? $params['hint2'] : '').'</td>' : '').
                        '</tr>';
                $item_params = array('cssclass' => 'c-access-subcontrol-item');
                if (isset($params['value'])) {
                    $item_params['value'] = $params['value'];
                }
                foreach ($params['items'] as $id => $item_name) {
                    if ($group) {
                        $inherited[$name.'.'.$id] = 1;
                    }
                    $html .= $this->getItemHtml($name.'.'.$id, htmlspecialchars($item_name), 'checkbox', $item_params, $rights, $inherited);
                }
                return $html;
            case 'selectlist':
                if (!isset($params['options']) || !$params['options']) {
                    return '';
                }
                if (isset($params['hint1']) && $params['hint1'] == 'all_select') {
                    $own = isset($rights[$name.'.all']) ? $rights[$name.'.all'] : '';
                    if ($own === '') {
                        reset($params['items']);
                        $k = key($params['items']);
                        $own = isset($rights[$name.'.'.$k]) ? $rights[$name.'.'.$k] : '';
                        foreach ($params['items'] as $id => $item_name) {
                            $item_v = isset($rights[$name.'.'.$id]) ? $rights[$name.'.'.$id] : key($params['options']);
                            if ($item_v != $own) {
                                $own = '';
                                break;
                            } else {
                                $own = $item_v;
                            }
                        }
                        $own = (string)$own;
                    }
                    $group = $inherited && isset($inherited[$name.'.all']) ? $inherited[$name.'.all'] : null;
                    $params['hint1'] = '<span class="c-access-cb-all nm"><label><select name="app['.$name.'.all]"><option value=""></option>';
                    foreach ($params['options'] as $v => $n) {
                        $params['hint1'].= '<option '.($own === (string)$v ? 'selected':'').' value="'.$v.'">'.$n.'</option>';
                    }
                    $params['hint1'].= '</select></label></span>';
                    if($inherited !== null) {
                        $params['hint2'] = '<span class="c-access-cb-all">' . ($group && isset($params['options'][$group]) ? $params['options'][$group] : '') . '</span>';
                    }
                }
                $html = '<tr class="c-access-subcontrol-header'.($params['cssclass'] ? ' '.$params['cssclass'] : '').'">'.
                                '<td><div>'.$label.'</div></td>'.
                                ($inherited !== null ? '<td></td>' : '').
                                '<td><div class="hint">'.(isset($params['hint1']) ? $params['hint1'] : '').'</td>'.
                                ($inherited !== null ? '<td><div class="hint">'.(isset($params['hint2']) ? $params['hint2'] : '').'</td>' : '').
                        '</tr>';
                foreach ($params['items'] as $id => $item_name) {
                    if (isset($params['hint1']) && !isset($rights[$name.'.'.$id]) && !empty($rights[$name.'.all'])) {
                        $rights[$name.'.'.$id] = $rights[$name.'.all'];
                    }
                    $html .= $this->getItemHtml($name.'.'.$id, htmlspecialchars($item_name), 'select', array('cssclass' => 'c-access-subcontrol-item', 'options' => $params['options']), $rights, $inherited);
                }
                return $html;
            case 'header':
                if(!isset($params['tag'])) {
                    $params['tag'] = 'h2';
                }
                return '<tr'.($params['cssclass'] ? ' class="'.$params['cssclass'].'"' : '').'>'.
                            '<td colspan="'.($inherited !== null ? '4' : '2').'"><div><'.$params['tag'].'>'.$label.'</'.$params['tag'].'></div></td>'.
                        '</tr>';
            default:
                throw new waException('Unknown control: '.$type);
        }
    }

    /**
     * @param array $rights
     * @param null  $inherited
     * @return string
     * @throws \waException
     */
    private function getUI20HTML($rights = array(), $inherited=null)
    {
        if ($inherited !== null) {
            $html = '<div class="alert"><div class="flexbox space-8"><i class="fas fa-info-circle gray"></i><span>' .sprintf(_ws('Если пользователь унаследовал доступ от групп (%s), то вы можете только расширить его за счет установки персонального доступа (%s). Чтобы понизить уровень доступа измените или настройте группы, в которых состоит пользователь.'), '<i class="fas fa-users gray"></i>', '<i class="fas fa-user gray"></i>').'</span></div></div>';
            $html .= '<table class="c-access-app">';
        } else {
            $html = '<table class="c-access-app c-access-app-group">';
        }
        $addScriptForCB = FALSE;
        $addScriptForSelect = FALSE;

        foreach ($this->items as $item) {
            $html .= $this->getItemHTML($item['name'], $item['label'], $item['type'], $item['params'], $rights, $inherited);
            if ($item['type'] == 'list' && isset($item['params']['hint1']) && $item['params']['hint1'] == 'all_checkbox') {
                $addScriptForCB = TRUE;
            }

            if ($item['type'] == 'selectlist' && isset($item['params']['hint1']) && $item['params']['hint1'] == 'all_select') {
                $addScriptForCB = TRUE;
            }

            if ($item['type'] == 'select' || $item['type'] == 'selectlist') {
                $addScriptForSelect = TRUE;
            }
        }
        $html .= '</table>';

        $html .= '
            <script>(function() {
                // Make indicators change when user changes personal access
                var updateIndicator = function() {
                    var self = $(this),
                        tr = self.parents("table.c-access-app tr"),
                        changed = false;
                    if (tr.find("input[type=\"checkbox\"]:checked").size() > 0) {
                        changed = tr.find(".js-access-type-own").toggleClass("hidden", false).length > 0;
                        tr.find(".js-access-type-group").toggleClass("hidden", true);
                    } else {
                        changed = tr.find(".js-access-type-own").toggleClass("hidden", true).length > 0;
                        tr.find(".js-access-type-group").toggleClass("hidden", false);
                    }
                    if (changed) {
                        self.parents("form").trigger("wa.change");
                    }
                };
                $("table.c-access-app input[type=\"checkbox\"]:enabled").click(updateIndicator);';

        if ($addScriptForSelect) {
            $html .= '
                // Change resulting column for selects
                const updateIndicatorForSelect = function() {
                    const $self = $(this),
                        $tr = $self.closest("tr"),
                        group_value = parseInt($tr.find("input.g-value").val(), 10),
                        personal_value = parseInt($self.val(), 10);

                        $tr.find(".js-access-type-own").toggleClass("hidden", !(group_value !== personal_value));
                        $tr.find(".js-access-type-group").toggleClass("hidden", (group_value !== personal_value));
                    
                };
                $("table.c-access-app select").change(updateIndicatorForSelect);';
        }

        if ($addScriptForCB) {
            $html .= <<<HTML
                // Logic for "all" checkboxes
                /** if $(this) is checked, then check and disable all checkboxes starting with the same name (minus `.all`)
                  * if not checked, then enable all those checkboxes. */
                var handler = function() {
                    var cb = $(this);
                    cb.parents("table.c-access-app")
                        .find("input[type=\"checkbox\"][name^=\""+cb.attr("name").replace(/\.all]/,"")+"\"]")
                        .each(function(k,cb2) {
                            cb2 = $(cb2);
                            if (cb.is(":checked")) {
                                cb2.attr("checked", true).prop("checked", true).attr("disabled", true);
                                updateIndicator.call(cb2[0]);
                            } else {
                                cb2.attr("checked", false).prop("checked", false).attr("disabled", false);
                                updateIndicator.call(cb2[0]);
                            }
                        });
                    cb.attr("disabled", false);
                    if (cb.val() !== "") {
                    cb.parents("table.c-access-app")
                        .find("select[name^=\""+cb.attr("name").replace(/\.all]/,"")+"\"]").each(function (k,cb2) {
                            cb2 = $(cb2);
                            cb2.val(cb.val());
                            updateIndicatorForSelect.call(cb2[0]);
                        });
                    }
                };
                /* For each enabled "all" checkbox in a table.c-access-app:
                   - Add an onclick handler
                   - Call the handler initially, if `all` is checked. */
                $("table.c-access-app .c-access-cb-all select").each(function(k,cb) {
                    cb = $(cb).change(handler);
                    //handler.call(cb[0]);
                });
                $("table.c-access-app .c-access-subcontrol-item select").change(function () {
                    var el = $(this);
                    var all = el.closest("table.c-access-app").find(".c-access-cb-all select");
                    if (all.val() !== $(this).val()) {
                        all.val('');
                    }
                });
                $("table.c-access-app .c-access-cb-all input:enabled").each(function(k,cb) {
                    cb = $(cb).click(handler);
                    if (cb.is(":checked")) {
                        handler.call(cb[0]);
                    }
                });
HTML;
        }

        $html .= '
            }).call({});</script>';

        return $html;
    }

    /**
     * @param      $name
     * @param      $label
     * @param      $type
     * @param      $params
     * @param      $rights
     * @param null $inherited
     * @return string
     * @throws \waException
     */
    private function getUI20ItemHTML($name, $label, $type, $params, $rights, $inherited=null)
    {
        $own = isset($rights[$name]) ? $rights[$name] : false;
        $group = $inherited && isset($inherited[$name]) ? $inherited[$name] : null;
        if (!isset($params['cssclass'])) {
            $params['cssclass'] = '';
        }
        switch ($type) {
            case 'select':
                if (!isset($params['options']) || !$params['options']) {
                    return '';
                }
                $own = ifempty($own, 0);
                $group = ifempty($group, 0);

                $o = $params['options'];
                $oHTML = array();
                foreach($o as $val => $opt) {
                    if($inherited !== null){
                        $oHTML[] = '<option value="'.$val.'"'.(($group != 0 && $own < $group && $group==$val) || ($own > $group && $own==$val) ? ' selected' : '').($val < $group ? ' disabled' : '').'>'.htmlspecialchars($opt).'</option>';
                    }else{
                        $oHTML[] = '<option value="'.$val.'"'.($own==$val ? ' selected' : '').'>'.htmlspecialchars($opt).'</option>';
                    }
                }
                $oHTML = implode('', $oHTML);

                // corner case when option of this key (group) not exists
                if (!isset($o[$group])) {
                    $group = key($o);
                }

                return '<tr'.($params['cssclass'] ? ' class="'.$params['cssclass'].'"' : '').'>'.
                    '<td class="custom-py-8">'.$label.'</td>'.
                    '<td class="custom-py-8 align-right"><input type="hidden" name="app['.$name.']" value="0">'.
                    '<div class="wa-select custom-m-0"><select name="app['.$name.']">'.$oHTML.'</select></div>'.
                    '</td>'.

                    ($inherited !== null ? '<td class="custom-py-8 min-width align-center"><span class="js-access-type-own'.($own > $group ? '' : ' hidden').'" data-wa-tooltip-content="'._ws('Доступ установлен персонально для этого пользователя.').'"><i class="fas fa-user text-gray"></i></span><span class="js-access-type-group'.($own > $group ? ' hidden' : '').'" data-wa-tooltip-content="'._ws('Доступ унаследован от групп.').'"><i class="fas fa-users" style="color: var(--menu-glyph-color)"></i></span><input type="hidden" class="g-value" value="'.$group.'"></td>' : '').
                    '</tr>';
            case 'checkbox':
                return '<tr'.($params['cssclass'] ? ' class="'.$params['cssclass'].'"' : '').'>'.
                    '<td class="custom-py-8">'.$label.'</td>'.
                    '<td class="custom-py-8 min-width align-right">'.($group ? '<label><span class="wa-checkbox"><input type="checkbox" checked disabled><span><span class="icon"><i class="fas fa-check"></i></span></span></span></label>' : '<input type="hidden" name="app['.$name.']" value="0"><label><span class="wa-checkbox"><input type="checkbox" name="app['.$name.']" value="'.(isset($params['value']) ? $params['value'] : 1).'"'.($own ? ' checked="checked"' : '').'><span><span class="icon"><i class="fas fa-check"></i></span></span></span></label>').'</td>'.
                    ($inherited !== null ? '<td class="custom-py-8 min-width align-center"><span class="js-access-type js-access-type-own'.($own ? '' : ' hidden').'" data-wa-tooltip-content="'._ws('Доступ установлен персонально для этого пользователя.').'"><i class="fas fa-user text-gray"></i></span><span class="js-access-type js-access-type-group'.($own ? ' hidden' : '').'" data-wa-tooltip-content="'._ws('Доступ унаследован от групп.').'"><i class="fas fa-users" style="color: var(--menu-glyph-color)"></i></span></td>' : '').
                    '</tr>';
            case 'always_enabled':
                $html = '<td class="custom-py-8">'.$label.'</td>'.
                    '<td class="custom-py-8 min-width align-right"><label><span class="wa-checkbox"><input type="checkbox" checked disabled><span><span class="icon"><i class="fas fa-check"></i></span></span></span></label></td>'.
                    ($inherited !== null ? '<td class="custom-py-8 min-width"></tdclass>' : '');
                return '<tr'.($params['cssclass'] ? ' class="'.$params['cssclass'].'"' : '').'>'.$html.'</tr>';
            case 'list':
                if (isset($params['hint1']) && $params['hint1'] == 'all_checkbox') {
                    $own = isset($rights[$name.'.all']) ? $rights[$name.'.all'] : '';
                    $group = $inherited && isset($inherited[$name.'.all']) ? $inherited[$name.'.all'] : null;
                    $params['hint1'] = '<input type="hidden" name="app['.$name.'.all]" value="0"><label class="c-access-cb-all">'.($inherited === null ? '<span class="gray custom-mr-16">'._ws('all').'</span>' : '').'<span class="wa-checkbox"><input type="checkbox" name="app['.$name.'.all]" value="1"'.($own ? ' checked="checked"' : '').'><span><span class="icon"><i class="fas fa-check"></i></span></span></span>'.($inherited !== null ? '<span class="gray custom-ml-16">'._ws('all').'</span>' : '').'</label>';
                }
                $html = '<tr class="c-access-subcontrol-header'.($params['cssclass'] ? ' '.$params['cssclass'] : '').'">'.
                    '<td class="custom-py-8">'.$label.'</td>'.
                    '<td'.($inherited !== null ? ' colspan="2"' : '').' class="custom-py-8">'.(isset($params['hint1']) ? $params['hint1'] : '').'</td>'.
                    '</tr>';
                $item_params = array('cssclass' => 'c-access-subcontrol-item');
                if (isset($params['value'])) {
                    $item_params['value'] = $params['value'];
                }
                foreach ($params['items'] as $id => $item_name) {
                    if ($group) {
                        $inherited[$name.'.'.$id] = 1;
                    }
                    $html .= $this->getItemHtml($name.'.'.$id, htmlspecialchars($item_name), 'checkbox', $item_params, $rights, $inherited);
                }
                return $html;
            case 'selectlist':
                if (!isset($params['options']) || !$params['options']) {
                    return '';
                }
                if (isset($params['hint1']) && $params['hint1'] == 'all_select') {
                    $own = isset($rights[$name.'.all']) ? $rights[$name.'.all'] : '';
                    if ($own === '') {
                        reset($params['items']);
                        $k = key($params['items']);
                        $own = isset($rights[$name.'.'.$k]) ? $rights[$name.'.'.$k] : '';
                        foreach ($params['items'] as $id => $item_name) {
                            $item_v = isset($rights[$name.'.'.$id]) ? $rights[$name.'.'.$id] : key($params['options']);
                            if ($item_v != $own) {
                                $own = '';
                                break;
                            } else {
                                $own = $item_v;
                            }
                        }
                        $own = (string)$own;
                    }
                    $group = $inherited && isset($inherited[$name.'.all']) ? $inherited[$name.'.all'] : null;
                    $params['hint1'] = '<div class="wa-select c-access-cb-all nm small"><select name="app['.$name.'.all]"><option value=""></option>';
                    foreach ($params['options'] as $v => $n) {
                        if($inherited !== null){
                            $params['hint1'].= '<option '.($own === (string)$v ? 'selected':'').($v < $group ? ' disabled' : '').' value="'.$v.'">'.$n.'</option>';
                        }else{
                            $params['hint1'].= '<option '.($own === (string)$v ? 'selected':'').' value="'.$v.'">'.$n.'</option>';
                        }
                    }
                    $params['hint1'].= '</select></div>';
                }
                $html = '<tr class="c-access-subcontrol-header'.($params['cssclass'] ? ' '.$params['cssclass'] : '').'">'.
                    '<td class="custom-py-8">'.$label.'</td>'.
                    '<td'.($inherited !== null ? ' colspan="2"' : '').' class="custom-py-8">'.(isset($params['hint1']) ? $params['hint1'] : '').'</td>'.
                    '</tr>';
                foreach ($params['items'] as $id => $item_name) {
                    if (isset($params['hint1']) && !isset($rights[$name.'.'.$id]) && !empty($rights[$name.'.all'])) {
                        $rights[$name.'.'.$id] = $rights[$name.'.all'];
                    }
                    $html .= $this->getItemHtml($name.'.'.$id, htmlspecialchars($item_name), 'select', array('cssclass' => 'c-access-subcontrol-item', 'options' => $params['options']), $rights, $inherited);
                }
                return $html;
            case 'header':
                if(!isset($params['tag'])) {
                    $params['tag'] = 'h2';
                }
                return '<tr'.($params['cssclass'] ? ' class="'.$params['cssclass'].'"' : '').'>'.
                    '<td colspan="'.($inherited !== null ? '3' : '2').'"><'.$params['tag'].'>'.$label.'</'.$params['tag'].'></td>'.
                    '</tr>';
            default:
                throw new waException('Unknown control: '.$type);
        }
    }
}

// EOF
