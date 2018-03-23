<?php
/* @var $this AdminController  */
/* @var $dataProvider CActiveDataProvider */

// DO NOT REMOVE This is for automated testing to validate we see that page
echo viewHelper::getViewTestTag('configurePlugin');
?>

<div class="col-lg-12">
    <div class="pagetitle h3"><?php echo gT("Plugin:") . ' ' . $plugin['name']; ?></div>

    <ul class="nav nav-tabs" id="settingTabs">
        <li role="presentation" class="active">
            <a role="tab" data-toggle="tab" href='#overview'><?php eT("Overview"); ?></a>
        </li>
        <li role="presentation">
            <a role="tab" data-toggle="tab" href='#settings'><?php eT("Settings"); ?></a>
        </li>
    </ul>

    <div class="tab-content">

        <div id="overview" class="tab-pane active">
            <?php $this->renderPartial(
                './pluginmanager/overview',
                [
                    'plugin' => $plugin
                ]
            ); ?>
        </div>

        <div id="settings" class="tab-pane">
            <?php if ($settings):
                    $this->widget(
                        'ext.SettingsWidget.SettingsWidget',
                        array(
                            'settings'        => $settings,
                            'formHtmlOptions' => [
                                'id' => "pluginsettings-{$plugin['name']}",
                            ],
                            'labelWidth'      =>4,
                            'controlWidth'    =>6,
                            'method'          => 'post',
                            'additionalHtml'  => "
                                <div class='form-group'>
                                <div class='col-sm-6 col-md-offset-4'>
                                ". (Permission::model()->hasGlobalPermission('settings','update')?"
                                <button name='save' class='btn btn-success' type='submit'><span class='fa fa-floppy-o'></span>&nbsp;".gT('Save')."</button>
                                <button name='redirect' value='" . App()->createUrl("admin/pluginmanager/sa/index") . "' class='btn btn-default' type='submit'><span class='fa fa-saved'></span>&nbsp;".gT('Save and close')."</button>
                                ":'')."
                                <a class='btn btn-danger' href='" . App()->createurl('admin/pluginmanager/sa/index') . "'>".gT('Close')."</a>
                                </div>
                               </div>
                            "
                        )
                    );
                ?>
                <?php else: ?>
                    <i><?php eT('This plugin has no settings.'); ?></i>
                <?php endif; ?>
        </div>
    </div>
</div>
