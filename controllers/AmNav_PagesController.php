<?php
namespace Craft;

/**
 * Nodes controller
 */
class AmNav_NodesController extends BaseController
{
    /**
     * Saves a new node.
     */
    public function actionSaveNewNode()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        // New NodeModel
        $node = new AmNav_NodeModel();
        $returnData  = array(
            'success' => false,
            'message' => Craft::t('Not all required fields are filled out.')
        );

        // Set attributes
        $attributes = craft()->request->getPost();
        if (! empty($attributes['navId']) && ! empty($attributes['name']) && ! empty($attributes['url'])) {
            // Make sure we save a valid URL
            if (substr($attributes['url'], 0, 3) == 'www') {
                $attributes['url'] = 'http://' . $attributes['url'];
            }

            // Is there an entry ID available?
            if (isset($attributes['entryId'])) {
                $node->setAttribute('entryId', $attributes['entryId']);
            }

            $node->setAttributes(array(
                'navId'    => $attributes['navId'],
                'parentId' => (int)$attributes['parentId'],
                'name'     => $attributes['name'],
                'url'      => $attributes['url'],
                'blank'    => isset($attributes['blank']) ? $attributes['blank'] == 'true' : false,
                'enabled'  => true,
                'locale'   => $attributes['locale']
            ));

            // Save the node!
            if (($node = craft()->amNav_node->saveNode($node)) !== false) {
                $returnData['success']  = true;
                $returnData['message']  = Craft::t('Node added.');
                $returnData['nodeData'] = $node;

                // Get parent options
                $nodes = craft()->amNav->getNodesByNavigationId($node->navId, $attributes['locale']);
                $variables['selected'] = $node->parentId;
                $variables['parentOptions'] = craft()->amNav->getParentOptions($nodes);
                $returnData['parentOptions'] = $this->renderTemplate('amNav/_build/parent', $variables, true);
            }
        }

        // Return result
        $this->returnJson($returnData);
    }

    /**
     * Saves a node.
     */
    public function actionSaveNode()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $nodeId = craft()->request->getRequiredPost('nodeId');

        // Get node
        $node = craft()->amNav_node->getNodeById($nodeId);
        if (! $node) {
            throw new HttpException(404);
        }

        // Set attributes
        $attributes = craft()->request->getPost();
        $node->setAttributes(array(
            'name'     => $attributes['name'],
            'blank'    => $attributes['blank'],
            'enabled'  => $attributes['enabled']
        ));
        // Is there an URL available?
        if (isset($attributes['url'])) {
            // Make sure we save a valid URL
            if (substr($attributes['url'], 0, 3) == 'www') {
                $attributes['url'] = 'http://' . $attributes['url'];
            }
            $attributes['url'] = str_ireplace('__home__', '', $attributes['url']);
            $node->setAttribute('url', $attributes['url']);
        }

        // Save the node!
        $returnData = array('success' => false);
        if (($node = craft()->amNav_node->saveNode($node)) !== false) {
            $returnData['success']  = true;
            $returnData['message']  = Craft::t('Node saved.');
            $returnData['nodeData'] = $node;
        }
        $this->returnJson($returnData);
    }

    /**
     * Moves a node.
     */
    public function actionMoveNode()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $nodeId = craft()->request->getRequiredPost('nodeId');

        // Get node
        $node = craft()->amNav_node->getNodeById($nodeId);
        if (! $node) {
            throw new HttpException(404);
        }

        // Get post values
        $prevId   = craft()->request->getPost('prevId', false);
        $parentId = craft()->request->getPost('parentId', 0);

        // Move node!
        $result = craft()->amNav_node->moveNode($node, $parentId, $prevId);

        // Get parent options
        $nodes = craft()->amNav->getNodesByNavigationId($node->navId, $node->locale);
        $variables['selected'] = $node->id;
        $variables['parentOptions'] = craft()->amNav->getParentOptions($nodes);
        $parentOptions = $this->renderTemplate('amNav/_build/parent', $variables, true);

        $returnData = array(
            'success'       => $result,
            'parentOptions' => $parentOptions
        );
        $this->returnJson($returnData);
    }

    /**
     * Deletes a node.
     */
    public function actionDeleteNode()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $nodeId = craft()->request->getRequiredPost('nodeId');

        // Get node
        $node = craft()->amNav_node->getNodeById($nodeId);
        if (! $node) {
            throw new HttpException(404);
        }

        $result = craft()->amNav_node->deleteNodeById($nodeId);

        // Get parent options
        $nodes = craft()->amNav->getNodesByNavigationId($node->navId, $node->locale);
        $variables['selected'] = 0;
        $variables['parentOptions'] = craft()->amNav->getParentOptions($nodes);
        $parentOptions = $this->renderTemplate('amNav/_build/parent', $variables, true);

        $returnData = array(
            'success'       => $result,
            'message'       => Craft::t('Node deleted.'),
            'parentOptions' => $parentOptions
        );
        $this->returnJson($returnData);
    }

    /**
     * Get HTML to edit a node.
     */
    public function actionGetEditorHtml()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $nodeId = craft()->request->getRequiredPost('nodeId');

        // Get node
        $variables['node'] = craft()->amNav_node->getNodeById($nodeId);
        if (! $variables['node']) {
            throw new HttpException(404);
        }

        $returnData['html'] = $this->renderTemplate('amNav/_build/editor', $variables, true);

        $this->returnJson($returnData);
    }
}