<?php
$schemaWriter = new Claude_Cowork_Site_Writer();
$postSchema = $schemaWriter->describeWrites('post');
checkTrue('post discovery includes body and thumbnail writes', count(array_diff(['post_content', 'featured_image_id'], $postSchema['updateFields'])) === 0);
checkTrue('post type is creation-only', in_array('post_type', $postSchema['createFields'], true) && !in_array('post_type', $postSchema['updateFields'], true));
checkTrue('author is not advertised as writable', !in_array('post_author', $postSchema['updateFields'], true));
check('unimplemented kind discovery is unknown, not empty', $schemaWriter->describeWrites('option'), null);
WP_Fake::$posts[9432] = ['ID' => 9432, 'post_title' => 'Discovery', 'post_type' => 'post', 'post_status' => 'draft'];
$schemaEngine = new Engine('schema-token-at-least-16', [], null, null, null, null, $schemaWriter);
$answer = $schemaEngine->handle(['token' => 'schema-token-at-least-16', 'action' => 'content.get', 'params' => ['kind' => 'post', 'id' => 9432]]);
check('content.get carries the real writer schema', $answer['writeSchema'] ?? null, $postSchema);
