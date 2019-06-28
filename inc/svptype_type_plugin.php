<?php
/**
 * Ce fichier contient l'API de gestion des types de plugin et de leurs affectations aux plugins.
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}


// -----------------------------------------------------------------------
// -------------------------- TYPES DE PLUGIN ----------------------------
// -----------------------------------------------------------------------

/**
 * Retourne la description complète du type de plugin ou une information donnée uniquement.
 *
 * @api
 *
 * @param string     $typologie
 *        Identifiant de la page ou de la composition.
 * @param string|int $type
 *        Information spécifique à retourner ou vide pour retourner toute la description.
 * @param string     $information
 *        Information spécifique à retourner ou vide pour retourner toute la description.
 *
 * @return array|string
 *        La description complète ou un champ précis demandé pour une page donnée. Les champs
 *        de type tableau sont systématiquement désérialisés et si demandé, les champs textuels peuvent être
 *        traités avec la fonction typo().
 */
function type_plugin_lire($typologie, $type, $information = '') {

	static $description_type = array();
	static $configurations = array();

	if (!isset($description_type[$typologie][$type])) {
		// Déterminer les informations du groupe typologique si il n'est pas encore stocké.
		if (!isset($configurations[$typologie])) {
			include_spip('inc/config');
			$configurations[$typologie] = lire_config("svptype/typologies/${typologie}", array());
		}

		// Chargement de la description nécessaire du type de plugin en base de données.
		// -- seules l'id, l'id_parent, la profondeur, l'identifiant typologique, le titre et le descriptif sont utiles.
		$select = array('id_mot', 'id_parent', 'identifiant', 'profondeur', 'titre', 'descriptif');
		// -- on construit la condition soit sur l'id_mot soit sur l'identifiant en fonction de ce qui est passé
		//    dans le paramètre $type.
		if ($id_mot = intval($type)) {
			$where = array(
				'id_mot=' . $id_mot,
			);
		} else {
			$where = array(
				'id_groupe=' . intval($configurations[$typologie]['id_groupe']),
				'identifiant=' . sql_quote($type)
			);
		}
		$description = sql_fetsel($select, 'spip_mots', $where);

		// Sauvegarde de la description de la page pour une consultation ultérieure dans le même hit.
		if ($description) {
			// Traitements des champs entiers id et profondeur
			$description['id_mot'] = intval($description['id_mot']);
			$description['id_parent'] = intval($description['id_parent']);
			$description['profondeur'] = intval($description['profondeur']);

			// Stockage de la description
			$description_type[$typologie][$type] = $description;
		} else {
			// En cas d'erreur stocker une description vide
			$description_type[$typologie][$type] = array();
		}
	}

	if ($information) {
		if (isset($description_type[$typologie][$type][$information])) {
			$type_lu = $description_type[$typologie][$type][$information];
		} else {
			$type_lu = null;
		}
	} else {
		$type_lu = $description_type[$typologie][$type];
	}

	return $type_lu;
}


/**
 * Renvoie l'information brute demandée pour l'ensemble des types concernés
 * ou toute les descriptions si aucune information n'est explicitement demandée.
 *
 * @param string $typologie
 *        Typologie concernée : categorie ou tag.
 * @param array  $filtres
 *        Identifiant d'un champ de la description d'un contrôle.
 * @param array  $informations
 *        Identifiant d'un champ ou plusieurs champs de la description d'un type de plugin.
 *        Si l'argument est vide, la fonction renvoie les descriptions complètes.
 *
 * @return array
 *        Tableau de la forme `[type_controle]  information ou description complète`.
 */
function type_plugin_repertorier($typologie, $filtres = array(), $informations = array()) {

	// Utilisation d'une statique pour éviter les requêtes multiples sur le même hit.
	static $types = array();

	if (!isset($types[$typologie])) {
		// On récupère l'id du groupe pour le type précisé (categorie, tag).
		include_spip('inc/config');
		$id_groupe = lire_config("svptype/typologies/${typologie}/id_groupe", 0);

		// On récupère la description complète de toutes les catégories de plugin
		$from = array('spip_mots');
		$where = array('id_groupe=' . $id_groupe);
		$order_by = array('identifiant');
		$types[$typologie] = sql_allfetsel('*', $from, $where, '', $order_by);
	}

	// Refactoring du tableau suivant les champs demandés et application des filtres.
	$types_filtrees = array();
	$informations = $informations ? array_flip($informations) : array();
	foreach ($types[$typologie] as $_cle => $_type) {
		// On détermine si on retient ou pas le type.
		$filtre_ok = true;
		foreach ($filtres as $_critere => $_valeur) {
			if (isset($_type[$_critere]) and ($_type[$_critere] != $_valeur)) {
				$filtre_ok = false;
				break;
			}
		}

		// Ajout du type si le filtre est ok.
		if ($filtre_ok) {
			$types_filtrees[] = $informations
				? array_intersect_key($types[$typologie][$_cle], $informations)
				: $types[$typologie][$_cle];
		}
	}

    return $types_filtrees;
}


/**
 * Importe une liste de types appartenant à la même typologie.
 *
 * @param string $typologie
 *        Typologie concernée : categorie ou tag.
 * @param array  $liste
 *        Tableau des types présenté comme une arborescence ou à plat suivant la typologie.
 *
 * @return bool|int
 *         Nombre de catégories ajoutées.
 */
function type_plugin_importer($typologie, $liste) {

	// Initialisation du nombre de types ajoutés.
	$types_ajoutes = 0;

	if ($liste) {
		// Acquérir la configuration de la typologie.
		include_spip('inc/config');
		$config_typologie = lire_config("svptype/typologies/${typologie}", array());

		if ($id_groupe = intval($config_typologie['id_groupe'])) {
			// Identification des champs acceptables pour un type.
			include_spip('base/objets');
			$description_table = lister_tables_objets_sql('spip_mots');
			$champs = $description_table['field'];

			include_spip('action/editer_objet');
			include_spip('inc/svptype_mot');
			foreach ($liste as $_type) {
				// On teste l'existence du type racine :
				// - si il n'existe pas on le rajoute,
				// - sinon on ne fait rien.
				// Dans tous les cas, on réserve l'id.
				if (!$id_type = type_plugin_lire($typologie, $_type['identifiant'], 'id_mot')) {
					// On insère le type racine (id_parent à 0).
					$set = array_intersect_key($_type, $champs);
					$set['id_parent'] = 0;
					$id_type = objet_inserer('mot', $id_groupe, $set);

					// Enregistrement du type ajouté.
					++$types_ajoutes;
				}

				// On traite maintenant les sous-types si :
				// -- le groupe est arborescent
				// -- il existe des sous-types dans le fichier pour le type racine
				// -- on est sur que le type racine existe
				if ($config_typologie['est_arborescente']
				and isset($_type['sous-types'])
				and $id_type) {
					// On insère les sous-types si ils ne sont pas déjà présentes dans la base.
					foreach ($_type['sous-types'] as $_sous_type) {
						if (!type_plugin_lire($typologie, $_sous_type['identifiant'], 'id_mot')) {
							// On insère le sous-type feuille sous son parent (un seul niveau permis).
							$set = array_intersect_key($_sous_type, $champs);
							$set['id_parent'] = $id_type;
							if (objet_inserer('mot', $id_groupe, $set)) {
								// Enregistrement du type ajouté.
								++$types_ajoutes;
							}
						}
					}
				}
			}
		}
	}

	return $types_ajoutes;
}


/**
 * Importe une liste de types appartenant à la même typologie.
 *
 * @param string $typologie
 *        Typologie concernée : categorie ou tag.
 *
 * @return array
 *         Tableau des types exportés.
 */
function type_plugin_exporter($typologie) {

	// Initialisation du nombre de types ajoutés.
	$types_exportes = array();

	// Déterminer les informations du groupe typologique.
	include_spip('inc/config');
	$config_typologie = lire_config("svptype/typologies/${typologie}", array());

	if ($id_groupe = intval($config_typologie['id_groupe'])) {
		// Identification des champs exportables pour un type.
		$champs = array('identifiant', 'titre', 'descriptif');

		// Extraction de tous les types racine pour la typologie concernée.
		// -- si la typologie est arborescente, les feuilles sont de profondeur 1 et sont acquises par la suite.
		$where = array(
			'id_groupe=' . $id_groupe, 'profondeur=0'
		);

		$types_racine = sql_allfetsel($champs, 'spip_mots', $where);
		if ($types_racine) {
			if ($config_typologie['est_arborescente']) {
				include_spip('inc/svptype_mot');
				$where[1] = 'profondeur=1';
				foreach ($types_racine as $_cle => $_type) {
					// Recherche des types enfants qui sont forcément des feuilles.
					$where = 'id_parent=' . type_plugin_lire($typologie, $_type['identifiant'], 'id_mot');
					$types_feuille = sql_allfetsel($champs, 'spip_mots', $where);

					// Construction du tableau arborescent des types
					$types_exportes[$_cle] = $_type;
					if ($types_feuille) {
						$types_exportes[$_cle]['sous-types'] = $types_feuille;
					}
				}
			} else {
				$types_exportes = $types_racine;
			}
		}
	}

	return $types_exportes;
}


function type_plugin_compter_enfants($typologie, $type) {

	// Le type de plugin peut être fourni soit par son id_mot soit par le couple (typologie, identifiant)
	// -- on cherche d'abord à déterminer l'id du mot-clé.
	$id_mot = type_plugin_lire($typologie, $type, 'id_mot');

	// On acquiert les enfants éventuels du type et on en calcule le nombre.
	include_spip('inc/svptype_mot');
	$nb_enfants = count(mot_lire_enfants($id_mot));

	return $nb_enfants;
}


// -----------------------------------------------------------------------
// ------------------ AFFECTATIONS DE TYPES DE PLUGIN --------------------
// -----------------------------------------------------------------------

/**
 * Renvoie les affectations aux plugins pour une typologie donnée.
 *
 * @param string $typologie
 *        Typologie concernée : categorie ou tag.
 * @param string $type
 *        Valeur d'un type donné pour la typologie concernée.
 *
 * @return array
 */
function type_plugin_affectation_repertorier($typologie, $type = '') {

	// Utilisation d'une statique pour éviter les requêtes multiples sur le même hit.
	static $affectations = array();

	if (!isset($affectations[$typologie])) {
		// On récupère l'id du groupe pour le type précisé (categorie, tag).
		include_spip('inc/config');
		$id_groupe = lire_config("svptype/typologies/${typologie}/id_groupe", 0);

		// On récupère la description complète de toutes les catégories de plugin
		$from = array('spip_plugins_typologies', 'spip_mots');
		$select = array(
			'spip_plugins_typologies.id_groupe',
			'spip_plugins_typologies.id_mot',
			'spip_mots.identifiant as identifiant_mot',
			'spip_plugins_typologies.prefixe'
		);
		$where = array(
			'spip_plugins_typologies.id_groupe=' . $id_groupe,
			'spip_plugins_typologies.id_mot=spip_mots.id_mot'
		);
		$order_by = array('spip_plugins_typologies.id_mot', 'spip_plugins_typologies.prefixe');
		$affectations[$typologie] = sql_allfetsel($select, $from, $where, '', $order_by);
	}

	// Filtrer sur le type souhaité si il existe.
	if (!$type) {
		$affectations_filtrees = $affectations[$typologie];
	} else {
		// Récupération de l'id du type
		include_spip('inc/svptype_mot');
		$id_type = type_plugin_lire($typologie, $type, 'id_mot');

		// Extraction des seules affectations au type.
		$affectations_filtrees = array();
		foreach ($affectations[$typologie] as $_affectation) {
			if ($_affectation['id_mot'] == $id_type) {
				$affectations_filtrees[] = $_affectation;
			}
		}
	}

    return $affectations_filtrees;
}


/**
 * Importe une liste d'affectation type-plugin pour une typologie donnée.
 * Le format du fichier est indépendant de la typologie.
 *
 * @param string $typologie
 *        Typologie concernée : categorie ou tag.
 * @param array  $affectations
 *        Tableau des affectations type-plugin (agnostique vis-à-vis de la typologie).
 *
 * @return int
 *         Nombre d'affectations ajoutées.
 */
function type_plugin_affectation_importer($typologie, $affectations) {

	// Initialisation du nombre d'affectations catégorie-plugin ajoutées.
	$nb_affectations_ajoutees = 0;

	if ($affectations) {
		// Déterminer les informations du groupe typologique.
		include_spip('inc/config');
		$config_typologie = lire_config("svptype/typologies/${typologie}", array());

		if ($id_groupe = intval($config_typologie['id_groupe'])) {
			// Initialisation d'un enregistrement d'affectation.
			$set = array(
				'id_groupe' => $id_groupe
			);

			include_spip('inc/svptype_mot');
			foreach ($affectations as $_affectation) {
				// On contrôle tout d'abord que l'affectation est correcte :
				// -- type et préfixe sont renseignés,
				// -- le type existe dans la base.
				if (!empty($_affectation['type'])
				and !empty($_affectation['prefixe'])
				and ($id_mot = type_plugin_lire($typologie, $_affectation['type'], 'id_mot'))) {
					// On vérifie que l'affectation n'existe pas déjà pour la typologie.
					$where = array(
						'id_mot=' . $id_mot,
						'prefixe=' . sql_quote($_affectation['prefixe'])
					);
					if (!sql_countsel('spip_plugins_typologies', $where)) {
						// In fine, on vérifie que le nombre maximal d'affectations pour un plugin n'est pas atteint
						// pour la typologie.
						$where = array(
							'prefixe=' . sql_quote($_affectation['prefixe']),
							'id_groupe=' . $id_groupe
						);
						if (!$config_typologie['max_affectations']
						or (sql_countsel('spip_plugins_typologies', $where) < $config_typologie['max_affectations'])) {
							// On peut insérer la nouvelle affectation
							$set['id_mot'] = $id_mot;
							$set['prefixe'] = $_affectation['prefixe'];
							if (sql_insertq('spip_plugins_typologies', $set)) {
								// Enregistrement de l'ajout de l'affectation.
								++$nb_affectations_ajoutees;
							}
						}
					}
				}
			}
		}
	}

	return $nb_affectations_ajoutees;
}


/**
 * Importe une liste de types appartenant à la même typologie.
 *
 * @param string $typologie
 *        Typologie concernée : categorie ou tag.
 *
 * @return array
 *         Tableau des types exportés.
 */
function type_plugin_affectation_exporter($typologie) {

	// Initialisation du nombre de types ajoutés.
	$affectations_exportees = array();

	// Déterminer les informations du groupe typologique.
	include_spip('inc/config');
	$config_typologie = lire_config("svptype/typologies/${typologie}", array());

	if ($id_groupe = intval($config_typologie['id_groupe'])) {
		// On récupère le préfixe et l'identifiant du type via une jointure avec spip_mots.
		$from = array('spip_plugins_typologies', 'spip_mots');
		$select = array(
			'spip_plugins_typologies.prefixe',
			'spip_mots.identifiant'
		);
		$where = array(
			'spip_plugins_typologies.id_groupe=' . $id_groupe,
			'spip_plugins_typologies.id_mot=spip_mots.id_mot'
		);

		$affectations_exportees = sql_allfetsel($select, $from, $where);
	}

	return $affectations_exportees;
}


function type_plugin_affectation_compter($typologie, $type) {

	// Initialisations statiques pour les performances.
	static $compteurs = array();
	static $configurations = array();

	// Déterminer les informations du groupe typologique si il n'est pas encore stocké.
	if (!isset($configurations[$typologie])) {
		include_spip('inc/config');
		$configurations[$typologie] = lire_config("svptype/typologies/${typologie}", array());
	}

	// Le type est fourni soit sous forme de son identifiant soit de son id.
	// On calcule dans tous les cas l'id.
	include_spip('inc/svptype_mot');
	if (!$id_mot = intval($type)) {
		// On a passé l'identifiant, il faut déterminer l'id du mot.
		$id_mot = type_plugin_lire($typologie, $type, 'id_mot');
	}

	// Recherche des affectations de plugin. Pour les catégories qui sont arborescentes, il faut distinguer :
	// -- les catégories de regroupement comme auteur
	// -- et les catégories feuille auxquelles sont attachés les plugins (auteur/extension)
	if (!isset($compteurs[$id_mot])) {
		// Initialisation de la condition sur le groupe de mots.
		$where = array('id_groupe=' . intval($configurations[$typologie]['id_groupe']));

		// Déterminer le mode de recherche suivant que :
		// - la typologie est arborescente ou pas
		// - le type est une racine ou une feuille.
		$profondeur = mot_lire_profondeur($id_mot);
		if ($configurations[$typologie]['est_arborescente']
		and ($profondeur == 0)) {
			// La typologie est arborescente et le type est une racine, il faut établir la condition sur les mots
			// feuille de cette racine.
			// -- On recherche les id_mot des feuilles de la racine
			$ids_enfant = mot_lire_enfants($id_mot);
			$where[] = sql_in('id_mot', $ids_enfant);
		} else {
			// La profondeur est > 0, c'est donc une feuille qui peut être affectée à un plugin : on étabit la condition
			// sur le mot lui-même.
			$where[] = 'id_mot=' . $id_mot;
		}

		$compteurs[$id_mot] = sql_countsel('spip_plugins_typologies', $where);
	}

	return $compteurs[$id_mot];
}
