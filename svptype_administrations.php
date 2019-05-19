<?php
/**
 * Ce fichier contient les fonctions de création, de mise à jour et de suppression
 * du schéma de données propres au plugin (tables et configuration).
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}


/**
 * Installation du schéma de données propre au plugin et gestion des migrations suivant
 * les évolutions du schéma.
 *
 * Le schéma comprend des tables et des variables de configuration.
 *
 * @api
 * @see controle_declarer_tables_principales()
 * @see controle_declarer_tables_interfaces()
 *
 * @param string $nom_meta_base_version
 * 		Nom de la meta dans laquelle sera rangée la version du schéma
 * @param string $version_cible
 * 		Version du schéma de données en fin d'upgrade
 *
 * @return void
 */
function svptype_upgrade($nom_meta_base_version, $version_cible){

	$maj = array();

	// Création des tables
	$maj['create'] = array(
		array('maj_tables', array('spip_groupes_mots', 'spip_mots', 'spip_plugins_typologies')),
	);

	include_spip('base/upgrade');
	maj_plugin($nom_meta_base_version, $version_cible, $maj);

	// Création des groupes de mots nécessaires à la typologie des plugins :
	// - plugin-categories : le groupe des catégories de plugin
	// - plugin-tags : le groupe des tags de plugin (pas initialisé pour l'instant)
	include_spip('action/editer_objet');

	// Groupe plugin-categories :
	// - groupe technique, arborescent et sans tables liées
	// - uniquement pour les administrateurs complets
	$groupe = array(
		'identifiant'       => 'plugin-categories',
		'titre'             => 'plugin-categories',
		'technique'         => 'oui',
		'mots_arborescents' => 'oui',
		'tables_liees'      => '',
		'minirezo'          => 'oui',
		'comite'            => 'non',
		'forum'             => 'non',
	);
	if (!objet_inserer('groupe_mots', null, $groupe)) {
		spip_log("Erreur lors de l'ajout du groupe plugin-categories", 'svptype' . _LOG_ERREUR);
	}
}


/**
 * Suppression de l'ensemble du schéma de données propre au plugin, c'est-à-dire
 * les tables et les variables de configuration.
 *
 * @api
 *
 * @param string $nom_meta_base_version
 * 		Nom de la meta dans laquelle sera rangée la version du schéma
 *
 * @return void
 */
function svptype_vider_tables($nom_meta_base_version) {

	// on supprime les groupes créés
	sql_delete('spip_groupes_mots', array('identifiant=' . sql_quote('plugin-categories')));

	// on supprime les champs additionnels des tables existantes
	sql_alter("TABLE spip_groupes_mots DROP COLUMN identifiant");
	sql_alter("TABLE spip_mots DROP COLUMN identifiant");

	// on efface les tables
	sql_drop_table('spip_plugins_typologies');

	// on efface la meta du schéma du plugin
	effacer_meta($nom_meta_base_version);
}
