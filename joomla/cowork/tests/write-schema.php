<?php
// Discovery uses the real writer's allowlists without opening a database connection.
if (!defined('_JEXEC')) define('_JEXEC', 1);
require_once __DIR__ . '/../com_claudecowork/site/src/Controller/JoomlaSiteWriter.php';
$schemaWriter = (new ReflectionClass(\Tracy\Component\ClaudeCowork\Site\Controller\JoomlaSiteWriter::class))->newInstanceWithoutConstructor();
$articleSchema = $schemaWriter->describeWrites('article');
checkTrue('article discovery includes title, publication and tag fields', count(array_diff(['title', 'publish_up', 'tags'], $articleSchema['updateFields'])) === 0);
checkTrue('readable author and creation date are not advertised as writable', !in_array('created_by', $articleSchema['updateFields'], true) && !in_array('created', $articleSchema['updateFields'], true));
check('identity rows cannot be created', $schemaWriter->describeWrites('user')['createFields'], null);
check('unknown relation schema stays unknown', $schemaWriter->describeWrites('articleAssociation'), null);
$menuSchema = $schemaWriter->describeWrites('menuItem');
checkTrue('nested updates include the engine move fields', $menuSchema['move'] && in_array('parent_id', $menuSchema['updateFields'], true) && in_array('move_after', $menuSchema['updateFields'], true));
checkTrue('menu creation exposes its distinct required fields', in_array('menutype', $menuSchema['createFields'], true) && !in_array('menutype', $menuSchema['updateFields'], true));
check('menu discovery reports the writer requirements', $menuSchema['requiredOnCreate'], ['title', 'menutype', 'link']);

class SchemaReadWriter extends FakeSiteWriter {
    public function describeWrites(string $kind): ?array { global $schemaWriter; return $schemaWriter->describeWrites($kind); }
}
$schemaReadWriter = new SchemaReadWriter();
$schemaReadWriter->store['article'][71] = ['id' => 71, 'title' => 'Discovery'];
$schemaEngine = new Engine('schema-token-at-least-16', [], null, null, null, null, $schemaReadWriter);
foreach (['content.list', 'content.get'] as $schemaAction) {
    $answer = $schemaEngine->handle(['token' => 'schema-token-at-least-16', 'action' => $schemaAction, 'params' => ['kind' => 'article', 'id' => 71]]);
    check($schemaAction . ' carries the writer schema', $answer['writeSchema'] ?? null, $articleSchema);
}
