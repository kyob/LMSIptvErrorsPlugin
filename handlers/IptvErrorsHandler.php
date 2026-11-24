<?php
/*
 * Copyright (C) 2026 Łukasz Kopiszka
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License Version 2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

class IptvErrorsHandler
{
    private function menuEntry($prio)
    {
        return array(
            'name' => trans('Błędy IPTV'),
            'link' => '?m=iptverrors',
            'tip' => trans('Monitoring błędów IPTV: dekodery, podsieci, grupy multicast'),
            'prio' => $prio,
        );
    }

    /**
     * Wpis trafia najpierw do Sieci IP. Sekcję TV tworzy LMSJamboxPlugin, który ładuje się
     * po tej wtyczce, więc smartyIptvErrors() przenosi wpis do TV, gdy ta sekcja istnieje.
     */
    public function menuIptvErrors(array $hook_data = array())
    {
        $hook_data['networks']['submenu'][] = $this->menuEntry(152);
        return $hook_data;
    }

    public function smartyIptvErrors(Smarty $hook_data)
    {
        global $menu;
        if (isset($menu['jambox']['submenu'], $menu['networks']['submenu'])) {
            foreach ($menu['networks']['submenu'] as $i => $item) {
                if (($item['link'] ?? '') === '?m=iptverrors') {
                    unset($menu['networks']['submenu'][$i]);
                }
            }
            $menu['jambox']['submenu'][] = $this->menuEntry(55);
        }

        $template_dirs = $hook_data->getTemplateDir();
        $plugin_templates = PLUGINS_DIR . DIRECTORY_SEPARATOR . LMSIptvErrorsPlugin::PLUGIN_DIRECTORY_NAME . DIRECTORY_SEPARATOR . 'templates';
        array_unshift($template_dirs, $plugin_templates);
        $hook_data->setTemplateDir($template_dirs);
        return $hook_data;
    }

    public function modulesDirIptvErrors(array $hook_data = array())
    {
        $plugin_modules = PLUGINS_DIR . DIRECTORY_SEPARATOR . LMSIptvErrorsPlugin::PLUGIN_DIRECTORY_NAME . DIRECTORY_SEPARATOR . 'modules';
        array_unshift($hook_data, $plugin_modules);
        return $hook_data;
    }

    public function accessTableInit()
    {
        $access = AccessRights::getInstance();
        $access->insertPermission(
            new Permission(
                'iptverrors_full_access',
                trans('Błędy IPTV'),
                '^iptverrors$'
            ),
            AccessRights::FIRST_FORBIDDEN_PERMISSION
        );
    }
}
