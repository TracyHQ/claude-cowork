<?php
/** Explicit local opt-in. Do not expose this administration script over HTTP. */
if (PHP_SAPI !== 'cli') exit(1);
$options=getopt('', ['root:', 'new-site']);
$root=realpath($options['root']??'');
if (!$root || !is_file($root.'/configuration.php')) { fwrite(STDERR,"Usage: php enable-content-reader.php --root=/path/to/joomla [--new-site]\n"); exit(1); }
define('_JEXEC',1);
define('JPATH_BASE',$root);
require JPATH_BASE.'/includes/defines.php';
require JPATH_BASE.'/includes/framework.php';
if (explode('.',JVERSION)[0]!=='6') { fwrite(STDERR,"This pilot requires Joomla 6\n"); exit(1); }
$container=\Joomla\CMS\Factory::getContainer();
$db=$container->get(\Joomla\Database\DatabaseInterface::class);
require JPATH_ADMINISTRATOR.'/components/com_claudecowork/lib/ContentIdentity.php';
ContentIdentity::install($db,array_key_exists('new-site',$options));
echo "Authenticated mapped content reader enabled. No public feed was opened.\n";
