<?php
/**
 * Prénoms courants sans ambiguïté (sans accents, en minuscules) : sert uniquement à signaler en « information »
 * un prénom habituellement porté par l'autre sexe. Ce n'est qu'un indice : jamais une erreur.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'm' => array(
		'jean', 'pierre', 'jacques', 'louis', 'paul', 'francois', 'henri', 'marcel', 'andre', 'rene', 'georges', 'joseph', 'michel', 'charles', 'antoine',
		'maurice', 'emile', 'eugene', 'leon', 'alfred', 'auguste', 'alexandre', 'etienne', 'gabriel', 'lucien', 'victor', 'fernand', 'raymond', 'robert',
		'roger', 'albert', 'gaston', 'clement', 'adolphe', 'edouard', 'felix', 'ferdinand', 'firmin', 'gilbert', 'guillaume', 'hippolyte', 'jules', 'julien',
		'laurent', 'leopold', 'marc', 'mathieu', 'nicolas', 'noel', 'octave', 'philippe', 'pascal', 'simon', 'theodore', 'thomas', 'vincent', 'xavier',
		'yves', 'daniel', 'david', 'denis', 'didier', 'christophe', 'bernard', 'benoit', 'bruno', 'cyril', 'damien', 'fabrice', 'franck', 'frederic',
		'gerard', 'gregoire', 'gregory', 'guy', 'hubert', 'hugues', 'isidore', 'jerome', 'joachim', 'justin', 'kevin', 'lionel', 'loic', 'ludovic',
		'mathias', 'matthieu', 'mickael', 'olivier', 'patrick', 'patrice', 'regis', 'remi', 'rodolphe', 'sebastien', 'serge', 'stephane', 'sylvain',
		'tristan', 'valentin', 'william', 'john', 'james', 'george', 'richard', 'peter', 'henry', 'edward', 'arthur', 'frank', 'walter', 'harold', 'ernest',
		'ernst', 'juan', 'jose', 'carlos', 'antonio', 'manuel', 'miguel', 'francisco', 'pedro', 'luis', 'javier', 'jorge', 'giovanni', 'giuseppe', 'mario',
		'luigi', 'hans', 'karl', 'fritz', 'otto', 'wilhelm', 'baptiste', 'casimir', 'cesar', 'constant', 'desire', 'elie', 'emmanuel', 'ernest', 'fabien',
		'florent', 'gustave', 'honore', 'ignace', 'jean-baptiste', 'jeremie', 'lazare', 'martin', 'modeste', 'narcisse', 'oscar', 'prosper', 'romain',
		'samuel', 'stanislas', 'sylvestre', 'timothee', 'urbain', 'vital', 'zacharie',
	),
	'f' => array(
		'marie', 'jeanne', 'marguerite', 'anne', 'francoise', 'catherine', 'louise', 'madeleine', 'elisabeth', 'suzanne', 'berthe', 'therese', 'josephine',
		'augustine', 'eugenie', 'leonie', 'alice', 'adele', 'adrienne', 'agathe', 'agnes', 'albertine', 'amelie', 'anna', 'antoinette', 'aimee', 'blanche',
		'bernadette', 'brigitte', 'caroline', 'cecile', 'celestine', 'celine', 'charlotte', 'christine', 'claire', 'clementine', 'clotilde', 'colette',
		'constance', 'denise', 'delphine', 'edith', 'eleonore', 'eliane', 'elise', 'emilie', 'emma', 'estelle', 'eve', 'fanny', 'fernande', 'flore',
		'florence', 'genevieve', 'georgette', 'germaine', 'gabrielle', 'ginette', 'gisele', 'helene', 'henriette', 'hortense', 'huguette', 'ines', 'irene',
		'isabelle', 'jacqueline', 'jeannette', 'josette', 'julie', 'juliette', 'justine', 'laure', 'laurence', 'lea', 'lucie', 'lucienne', 'lydie',
		'marcelle', 'margot', 'marthe', 'martine', 'mathilde', 'maria', 'marianne', 'michele', 'monique', 'nathalie', 'nicole', 'odette', 'olivia',
		'pauline', 'paulette', 'perrine', 'philomene', 'pierrette', 'rachel', 'raymonde', 'renee', 'rosalie', 'rose', 'sabine', 'sandrine', 'sarah',
		'simone', 'solange', 'sophie', 'stephanie', 'sylvie', 'valerie', 'veronique', 'victoire', 'victorine', 'virginie', 'yvette', 'yvonne', 'zoe',
		'mary', 'elizabeth', 'margaret', 'jane', 'ann', 'susan', 'emily', 'ruth', 'dorothy', 'helen', 'ana', 'carmen', 'dolores', 'pilar', 'rosa',
		'teresa', 'giovanna', 'giuseppina', 'gertrud', 'ingrid', 'hildegard', 'apolline', 'armelle', 'aurelie', 'clemence', 'cornelie', 'eulalie',
		'ernestine', 'euphrasie', 'felicie', 'fortunee', 'honorine', 'isabeau', 'jacquette', 'leontine', 'lucile', 'melanie',
		'noemie', 'octavie', 'ophelie', 'pelagie', 'rosine', 'rosette', 'sidonie', 'sylvanie', 'urbaine', 'zelie',
	),
);
