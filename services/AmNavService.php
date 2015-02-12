<?php
namespace Craft;

/**
 * Navigation service
 */
class AmNavService extends BaseApplicationComponent
{
    private $_navigation;
    private $_params;
    private $_parseHtml = false;
    private $_parseEnvironment = false;
    private $_siteUrl;
    private $_addTrailingSlash = false;
    private $_activeNodeIds = array();

    /**
     * Get all build navigations.
     *
     * @return array
     */
    public function getNavigations()
    {
        $navigationRecords = AmNav_NavigationRecord::model()->ordered()->findAll();
        return AmNav_NavigationModel::populateModels($navigationRecords);
    }

    /**
     * Get a navigation by its ID.
     *
     * @param int $navId
     *
     * @return AmNav_NavigationModel|null
     */
    public function getNavigationById($navId)
    {
        $navigationRecord = AmNav_NavigationRecord::model()->findById($navId);
        if ($navigationRecord) {
            return AmNav_NavigationModel::populateModel($navigationRecord);
        }
        return null;
    }

    /**
     * Get a navigation by its handle.
     *
     * @param string $handle
     *
     * @return AmNav_NavigationModel|null
     */
    public function getNavigationByHandle($handle)
    {
        $navigationRecord = AmNav_NavigationRecord::model()->findByAttributes(array('handle' => $handle));
        if ($navigationRecord) {
            return AmNav_NavigationModel::populateModel($navigationRecord);
        }
        return null;
    }

    /**
     * Get a navigation name by its handle.
     *
     * @param string $handle
     *
     * @return string|null
     */
    public function getNavigationNameByHandle($handle)
    {
        $navigationRecord = AmNav_NavigationRecord::model()->findByAttributes(array('handle' => $handle));
        if ($navigationRecord) {
            $navigation = AmNav_NavigationModel::populateModel($navigationRecord);
            return $navigation->name;
        }
        return null;
    }

    /**
     * Get all nodes by its navigation ID.
     *
     * @param int    $navId
     * @param string $locale
     *
     * @return array
     */
    public function getNodesByNavigationId($navId, $locale)
    {
        // Set necessary variables
        $this->_siteUrl = craft()->getSiteUrl();
        $this->_addTrailingSlash = craft()->config->get('addTrailingSlashesToUrls');

        // Start at the root by default
        $parentId = 0;

        // Do we have to start from a specific node ID?
        $startFromId = $this->_getParam('startFromId' , false);
        if ($startFromId !== false) {
            $parentId = $startFromId;
        }

        $nodes = craft()->amNav_node->getAllNodesByNavigationId($navId, $locale);
        if ($this->_parseHtml) {
            return $this->_buildNavHtml($nodes, $parentId);
        }
        return $this->_buildNav($nodes, $parentId);
    }

    /**
     * Get parent options for given nodes.
     *
     * @param array $nodes
     * @param bool  $skipFirst
     *
     * @return array
     */
    public function getParentOptions($nodes, $skipFirst = false)
    {
        $parentOptions = array();
        if (! $skipFirst) {
            $parentOptions[] = array(
                'label' => '',
                'value' => 0
            );
        }
        foreach ($nodes as $node) {
            $label = '';
            for ($i = 1; $i < $node['level']; $i++) {
                $label .= '    ';
            }
            $label .= $node['name'];

            $parentOptions[] = array(
                'label' => $label,
                'value' => $node['id']
            );
            if (isset($node['children'])) {
                foreach($this->getParentOptions($node['children'], true) as $childNode) {
                    $parentOptions[] = $childNode;
                }
            }
        }
        return $parentOptions;
    }

    /**
     * Saves a navigation.
     *
     * @param AmNav_NavigationModel $navigation
     *
     * @throws Exception
     * @return bool
     */
    public function saveNavigation(AmNav_NavigationModel $navigation)
    {
        // Navigation data
        if ($navigation->id) {
            $navigationRecord = AmNav_NavigationRecord::model()->findById($navigation->id);

            if (! $navigationRecord) {
                throw new Exception(Craft::t('No navigation exists with the ID “{id}”.', array('id' => $navigation->id)));
            }
        }
        else {
            $navigationRecord = new AmNav_NavigationRecord();
        }

        // Set attributes
        $navigationRecord->setAttributes($navigation->getAttributes());
        $navigationRecord->setAttribute('settings', json_encode($navigation->settings));

        // Validate
        $navigationRecord->validate();
        $navigation->addErrors($navigationRecord->getErrors());

        // Save navigation
        if (! $navigation->hasErrors()) {
            // Save in database
            return $navigationRecord->save();
        }
        return false;
    }

    /**
     * Delete a navigation by its ID.
     *
     * @param int $navId
     *
     * @return bool
     */
    public function deleteNavigationById($navId)
    {
        craft()->db->createCommand()->delete('amnav_nodes', array('navId' => $navId));
        return craft()->db->createCommand()->delete('amnav_navs', array('id' => $navId));
    }

    /**
     * Get a navigation structure as HTML.
     *
     * @param string $handle
     * @param array  $params
     *
     * @throws Exception
     * @return string
     */
    public function getNav($handle, $params)
    {
        $navigation = $this->getNavigationByHandle($handle);
        if (! $navigation) {
            throw new Exception(Craft::t('No navigation exists with the handle “{handle}”.', array('handle' => $handle)));
        }
        $this->_navigation = $navigation;
        // We want correct URLs now
        $this->_parseEnvironment = true;
        // Get the params
        $this->_setParams($params);
        // We want HTML returned
        $this->_parseHtml = true;
        // Return build HTML
        return $this->getNodesByNavigationId($navigation->id, craft()->language);
    }

    /**
     * Get a navigation structure without any HTML.
     *
     * @param string $handle
     * @param array  $params
     *
     * @throws Exception
     * @return array
     */
    public function getNavRaw($handle, $params)
    {
        $navigation = $this->getNavigationByHandle($handle);
        if (! $navigation) {
            throw new Exception(Craft::t('No navigation exists with the handle “{handle}”.', array('handle' => $handle)));
        }
        $this->_navigation = $navigation;
        // We want correct URLs now
        $this->_parseEnvironment = true;
        // Get the params
        $this->_setParams($params);
        // We don't want HTML returned
        $this->_parseHtml = false;
        // Return the array structure
        return $this->getNodesByNavigationId($navigation->id, craft()->language);
    }

    /**
     * Get an active node ID for a specific navigation's level.
     *
     * @param string $handle        Navigation handle.
     * @param int    $segmentLevel  Segment level.
     *
     * @return int|bool
     */
    public function getActiveNodeIdForLevel($handle, $segmentLevel = 1)
    {
        if (isset($this->_activeNodeIds[$handle][$segmentLevel])) {
            return $this->_activeNodeIds[$handle][$segmentLevel];
        }
        return false;
    }

    /**
     * Get a navigation structure as HTML.
     *
     * @param array $params
     *
     * @return string
     */
    public function getBreadcrumbs($params)
    {
        // Get the params
        $this->_setParams($params);
        // Return built HTML
        return $this->_buildBreadcrumbsHtml();
    }

    /**
     * Set parameters for the navigation HTML output.
     *
     * @param array $params
     */
    private function _setParams($params)
    {
        $this->_params = array();
        foreach ($params as $paramKey => $paramValue) {
            $this->_params[$paramKey] = $paramValue;
        }
    }

    /**
     * Get parameter value.
     *
     * @param string $name
     * @param mixed  $default
     *
     * @return mixed
     */
    private function _getParam($name, $default)
    {
        return isset($this->_params[$name]) ? $this->_params[$name] : $default;
    }

    /**
     * Parse URL.
     *
     * @param string $url
     *
     * @return string
     */
    private function _parseUrl($url)
    {
        $isAnchor   = substr(str_replace('{siteUrl}', '', $url), 0, 1) == '#';
        $isSiteLink = strpos($url, '{siteUrl}') !== false;
        $isHomepage = str_replace('{siteUrl}', '', $url) == '';
        $url        = str_replace('{siteUrl}', $this->_siteUrl, $url);
        if ($this->_addTrailingSlash && ! $isAnchor && $isSiteLink && ! $isHomepage) {
            $url .= '/';
        }
        return $url;
    }

    /**
     * Check whether the URL is currently active.
     *
     * @param array $node
     *
     * @return bool
     */
    private function _isNodeActive($node)
    {
        $url = $node['url'];
        $path = craft()->request->getPath();
        $segments = craft()->request->getSegments();
        $segmentCount = count($segments) > 0 ? count($segments) : 1;

        $url = str_replace('{siteUrl}', '', $url);
        if ($url == $path) {
            $this->_activeNodeIds[ $this->_navigation->handle ][ $segmentCount ] = $node['id'];
            return true;
        }
        if (count($segments)) {
            $found = false;
            $count = 1; // Start at second
            $segmentString = $segments[0]; // Add first
            while ($count < count($segments)) {
                if ($url == $segmentString) {
                    $found = true;
                    break;
                }
                $segmentString .= '/' . $segments[$count];
                $count ++;
            }
            if ($found) {
                $this->_activeNodeIds[ $this->_navigation->handle ][$count] = $node['id'];
                return true;
            }
        }
        return false;
    }

    /**
     * Get active entries based on URI.
     *
     * @return array
     */
    private function _getActiveEntries()
    {
        $entries = array();
        $segments = craft()->request->getSegments();

        // Add homepage
        $criteria = craft()->elements->getCriteria(ElementType::Entry);
        $criteria->uri = '__home__';
        $entry = $criteria->first();
        if ($entry) {
            $entries[] = $entry;
        }

        // Find other entries
        if (count($segments)) {
            $count = 0; // Start at second
            $segmentString = $segments[0]; // Add first
            while ($count < count($segments)) {
                // Get entry
                $criteria = craft()->elements->getCriteria(ElementType::Entry);
                $criteria->uri = $segmentString;
                $criteria->status = null;
                $entry = $criteria->first();

                // Add entry to active entries
                if ($entry) {
                    $entries[] = $entry;
                }

                // Search for next possible entry
                $count ++;
                if (isset($segments[$count])) {
                    $segmentString .= '/' . $segments[$count];
                }
            }
        }
        return $entries;
    }

    /**
     * Create the navigation based on parent IDs and order.
     *
     * @param array $nodes
     * @param int   $parentId
     * @param int   $level
     *
     * @return array
     */
    private function _buildNav($nodes, $parentId = 0, $level = 1)
    {
        // Do we have a maximum level?
        if ($this->_parseEnvironment) {
            $maxLevel = $this->_getParam('maxLevel' , false);
            if ($maxLevel !== false && $level > $maxLevel) {
                return false;
            }
        }

        $nav = array();
        foreach ($nodes as $node) {
            if ($node['parentId'] == $parentId) {
                // Do additional stuff if we use this function from the front end
                if ($this->_parseEnvironment) {
                    if ($node['enabled'] || $this->_getParam('overrideStatus', false)) {
                        $node['active'] = $this->_isNodeActive($node);
                        $node['url'] = $this->_parseUrl($node['url']);
                    }
                    else {
                        // Skip this node
                        continue;
                    }
                }

                $node['level'] = $level;
                $children = $this->_buildNav($nodes, $node['id'], $level + 1);
                if ($children) {
                    $node['children'] = $children;
                }
                $nav[] = $node;
            }
        }
        return $nav;
    }

    /**
     * Create the navigation HTML based on parent IDs and order.
     *
     * @param array $nodes
     * @param int   $parentId
     * @param int   $level
     *
     * @return string
     */
    private function _buildNavHtml($nodes, $parentId = 0, $level = 1)
    {
        // Do we have a maximum level?
        $maxLevel = $this->_getParam('maxLevel' , false);
        if ($maxLevel !== false && $level > $maxLevel) {
            return false;
        }

        // If we don't find any nodes at the end, don't return an empty UL
        $foundNodes = false;

        // Create UL
        $nav = '';
        if ($level == 1) {
            if (! $this->_getParam('excludeUl', false)) {
                $nav = sprintf("\n" . '<ul id="%1$s" class="%2$s">',
                    $this->_getParam('id', $this->_navigation->handle),
                    $this->_getParam('class', 'nav')
                );
            }
        } else {
            $nav = sprintf("\n" . '<ul class="%1$s">',
                $this->_getParam('classLevel' . $level, 'nav__level' . $level)
            );
        }

        // Add the nodes to the navigation, but only if they are enabled
        $count = 0;
        foreach ($nodes as $node) {
            if ($node['parentId'] == $parentId && ($node['enabled'] || $this->_getParam('overrideStatus', false))) {
                $count ++;
                $foundNodes = true;

                // Get children
                $children = $this->_buildNavHtml($nodes, $node['id'], $level + 1);

                // Set node classes
                $nodeClasses = array();
                if ($children) {
                    $nodeClasses[] = $this->_getParam('classChildren', 'has-children');
                }
                if ($this->_isNodeActive($node)) {
                    $nodeClasses[] = $this->_getParam('classActive', 'active');
                }
                if ($level == 1 && $count == 1) {
                    $nodeClasses[] = $this->_getParam('classFirst', 'first');
                }

                // Add curent node
                $nav .= sprintf("\n" . '<li%1$s><a%5$s href="%2$s"%3$s>%4$s</a>',
                    count($nodeClasses) ? ' class="' . implode(' ', $nodeClasses) . '"' : '',
                    $this->_parseUrl($node['url']),
                    $node['blank'] ? ' target="_blank"' : '',
                    $node['name'],
                    $this->_getParam('classBlank', false) !== false ? ' class="' . $this->_getParam('classBlank', false) . '"' : ''
                );

                // Add children to the navigation
                if ($children) {
                    $nav .= $children;
                }
                $nav .= '</li>';
            }
        }
        if ($level == 1) {
            if (! $this->_getParam('excludeUl', false)) {
                $nav .= "\n</ul>";
            }
        }
        else {
            $nav .= "\n</ul>";
        }
        if ($foundNodes) {
            return TemplateHelper::getRaw($nav);
        }
        else {
            return false;
        }
    }

    /**
     * Create the breadcrumbs HTML.
     *
     * @return string
     */
    private function _buildBreadcrumbsHtml()
    {
        // Get active entries
        $activeEntries = $this->_getActiveEntries();

        // Create breadcrumbs
        $length = count($activeEntries);
        $breadcrumbs = "\n" . sprintf('<%1$s%2$s%3$s xmlns:v="http://rdf.data-vocabulary.org/#">',
            $this->_getParam('wrapper', 'ol'),
            $this->_getParam('id', false) ? ' id="' . $this->_getParam('id', '') . '"' : '',
            $this->_getParam('class', false) ? ' class="' . $this->_getParam('class', '') . '"' : ''
        );
        foreach ($activeEntries as $index => $entry) {
            // First
            if ($index == 0) {
                $breadcrumbs .= sprintf("\n" . '<li typeof="v:Breadcrumb"><a href="%1$s" title="%2$s" rel="v:url" property="v:title">%2$s</a></li>',
                    $entry->url,
                    $this->_getParam('renameHome', $entry->title)
                );
            }
            // Last
            elseif ($index == $length - 1)
            {
                $breadcrumb = sprintf('<span property="v:title">%1$s</span>',
                    $entry->title
                );
                if ($this->_getParam('lastIsLink', false)) {
                    $breadcrumb = sprintf('<a href="%1$s" title="%2$s" rel="v:url" property="v:title">%2$s</a>',
                        $entry->url,
                        $entry->title
                    );
                }
                $breadcrumbs .= sprintf("\n" . '<li class="%1$s" typeof="v:Breadcrumb">%2$s</li>',
                    $this->_getParam('classLast', 'last'),
                    $breadcrumb
                );
            }
            else {
                $breadcrumbs .= sprintf("\n" . '<li typeof="v:Breadcrumb"><a href="%1$s" title="%2$s" rel="v:url" property="v:title">%2$s</a></li>',
                    $entry->url,
                    $entry->title
                );
            }
        }
        $breadcrumbs .= "\n" . sprintf('</%1$s>',
            $this->_getParam('wrapper', 'ol')
        );
        return TemplateHelper::getRaw($breadcrumbs);
    }
}