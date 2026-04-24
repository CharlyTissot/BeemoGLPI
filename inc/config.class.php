<?php
/**
 * PluginBeemoconnectConfig — Gestion clé/valeur de la configuration
 */
class PluginBeemoconnectConfig extends CommonDBTM {

    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string {
        return 'Configuration BeemoConnect';
    }

    public static function getAll(): array {
        global $DB;
        $out  = [];
        $iter = $DB->request(['FROM' => 'glpi_plugin_beemoconnect_configs']);
        foreach ($iter as $row) {
            $out[$row['name']] = $row['value'];
        }
        return $out;
    }

    public static function getValue(string $name, string $default = ''): string {
        global $DB;
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_beemoconnect_configs',
            'WHERE' => ['name' => $name],
            'LIMIT' => 1,
        ]);
        $row = $iter->current();
        return $row ? (string)$row['value'] : $default;
    }

    public static function setValue(string $name, string $value): void {
        global $DB;
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_beemoconnect_configs',
            'WHERE' => ['name' => $name],
            'LIMIT' => 1,
        ]);
        if ($iter->current()) {
            $DB->update('glpi_plugin_beemoconnect_configs', ['value' => $value], ['name' => $name]);
        } else {
            $DB->insert('glpi_plugin_beemoconnect_configs', ['name' => $name, 'value' => $value]);
        }
    }

    public static function setValues(array $data): void {
        foreach ($data as $name => $value) {
            if (in_array($name, ['imap_password', 'graph_client_secret']) && $value === '') {
                continue;
            }
            self::setValue($name, (string)$value);
        }
    }

    public static function getDefaultSections(): array {
        return [
            'Beemo avec un RAID dégradé'                                                           => ['enabled' => 1, 'urgency' => 4],
            'Beemo déconnectées du centre de sauvegarde'                                           => ['enabled' => 1, 'urgency' => 3],
            'Beemo ne pouvant synchroniser leurs données sur la Beemo distante'                    => ['enabled' => 1, 'urgency' => 3],
            "Beemo n'ayant que des jeux de sauvegardes locaux"                                     => ['enabled' => 1, 'urgency' => 3],
            'Beemo dont le disque est rempli à plus de 95%'                                        => ['enabled' => 1, 'urgency' => 3],
            "Beemo sans adresse email d'alerte ou de rapport"                                      => ['enabled' => 1, 'urgency' => 3],
            'Jeux de sauvegarde sans rétention'                                                    => ['enabled' => 1, 'urgency' => 3],
            'Jeux de sauvegarde serveur en erreur'                                                 => ['enabled' => 1, 'urgency' => 3],
            'Jeux de sauvegarde serveur ayant des erreurs de lecture'                              => ['enabled' => 1, 'urgency' => 3],
            "Jeux de sauvegarde serveur déconnectés n'ayant pas eu de sauvegarde depuis au moins 7 jours" => ['enabled' => 1, 'urgency' => 3],
            'Jeux de sauvegarde poste en erreur'                                                   => ['enabled' => 1, 'urgency' => 3],
            'Jeux de sauvegarde poste ayant des erreurs de lecture'                                => ['enabled' => 1, 'urgency' => 3],
            "Jeux de sauvegarde poste déconnectés n'ayant pas eu de sauvegarde depuis au moins 30 jours" => ['enabled' => 1, 'urgency' => 3],
            "Jeux de sauvegarde n'ayant pas été externalisés à 100% depuis au moins 7 jours"      => ['enabled' => 1, 'urgency' => 3],
            'Jeux de sauvegarde non planifiés'                                                     => ['enabled' => 1, 'urgency' => 3],
            'Jeux de sauvegarde ayant un versioning insuffisant (moins de 3 versions ou moins de 3 jours)' => ['enabled' => 1, 'urgency' => 3],
            'Jeux de sauvegarde en mode fichier ayant inclus c'                                    => ['enabled' => 1, 'urgency' => 3],
            'Jeux de sauvegarde image disque sur une machine virtuelle'                            => ['enabled' => 1, 'urgency' => 3],
            'Jeux de sauvegarde sauvegardant un volume en mode fichier et en mode image disque'    => ['enabled' => 1, 'urgency' => 3],
            'Jeu de sauvegarde nomade/BeeHive non activé'                                          => ['enabled' => 1, 'urgency' => 3],
            'Beemo2Beemo dont la licence est expirée'                                              => ['enabled' => 0, 'urgency' => 3],
        ];
    }

    public static function getSections(): array {
        $raw = self::getValue('sections_config', '{}');
        $dec = json_decode($raw, true);
        if (!is_array($dec) || empty($dec)) {
            return self::getDefaultSections();
        }
        return $dec;
    }
}
