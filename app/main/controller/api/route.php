<?php
/**
 * Created by PhpStorm.
 * User: exodus4d
 * Date: 06.06.15
 * Time: 03:34
 */

namespace Controller\Api;
use Controller;
use lib\Config;
use Model;


/**
 * Routes controller
 * Class Route
 * @package Controller\Api
 */
class Route extends Controller\AccessController {

    const ROUTE_SEARCH_DEPTH_DEFAULT = 1;

    /**
     * cache time for static jump data (e.g. K-Space stargates)
     * @var int
     */
    private $staticJumpDataCacheTime = 86400;

    /**
     * cache time for dynamic jump data (e.g. W-Space systems, Jumpbridges. ...)
     * @var int
     */
    private $dynamicJumpDataCacheTime = 10;

    /**
     * array system information grouped by systemId
     * @var array
     */
    private $nameArray = [];

    /**
     * array neighbour systems grouped by systemName
     * @var array
     */
    private $jumpArray = [];

    /**
     * array with systemName => systemId matching
     * @var array
     */
    private $idArray = [];

    /**
     * template for routeData payload
     * @var array
     */
    private $defaultRouteData = [
        'routePossible' => false,
        'routeJumps' => 0,
        'maxDepth' => self::ROUTE_SEARCH_DEPTH_DEFAULT,
        'depthSearched' => 0,
        'searchType' => '',
        'route' => [],
        'error' => ''
    ];

    /**
     * reset all jump data
     */
    protected function resetJumpData(){
        $this->nameArray = [];
        $this->jumpArray = [];
        $this->idArray = [];
    }

    /**
     * set static system jump data for this instance
     * the data is fixed and should not change
     * -> jump data includes JUST "static" connections (Stargates)
     * -> this data is equal for EACH route search (does not depend on map data)
     */
    private function setStaticJumpData(){
        $query = "SELECT * FROM system_neighbour";
        $rows = $this->getDB()->exec($query, null, $this->staticJumpDataCacheTime);

        if(count($rows) > 0){
            $this->updateJumpData($rows);
        }
    }

    /**
     * set/add dynamic system jump data for specific "mapId"´s
     * -> this data is dynamic and could change on any map change
     * -> (e.g. new system added, connection added/updated, ...)
     * @param array $mapIds
     * @param array $filterData
     */
    private function setDynamicJumpData($mapIds = [], $filterData = []){
        // make sure, mapIds are integers (protect against SQL injections)
        $mapIds = array_unique( array_map('intval', $mapIds), SORT_NUMERIC);

        if( !empty($mapIds) ){
            // map filter ---------------------------------------------------------------------------------------------
            $whereMapIdsQuery = (count($mapIds) == 1) ? " = " . reset($mapIds) : " IN (" . implode(', ', $mapIds) . ")";

            // connection filter --------------------------------------------------------------------------------------
            $whereQuery = "";
            $includeScopes = [];
            $includeTypes = [];
            $excludeTypes = [];
            $includeEOL = true;

            if( $filterData['stargates'] === true){
                // include "stargates" for search
                $includeScopes[] = 'stargate';
                $includeTypes[] = 'stargate';

            }

            if( $filterData['jumpbridges'] === true ){
                // add jumpbridge connections for search
                $includeScopes[] = 'jumpbridge';
                $includeTypes[] = 'jumpbridge';
            }

            if( $filterData['wormholes'] === true ){
                // add wormhole connections for search
                $includeScopes[] = 'wh';
                $includeTypes[] = 'wh_fresh';


                if( $filterData['wormholesReduced'] === true ){
                    $includeTypes[] = 'wh_reduced';
                }

                if( $filterData['wormholesCritical'] === true ){
                    $includeTypes[] = 'wh_critical';
                }

                if( $filterData['wormholesFrigate'] !== true ){
                    $excludeTypes[] = 'frigate';
                }

                if( $filterData['wormholesEOL'] === false ){
                    $includeEOL = false;
                }
            }

            // search connections -------------------------------------------------------------------------------------

            if( !empty($includeScopes) ){
                $whereQuery .= " `connection`.`scope` IN ('" . implode("', '", $includeScopes) . "') AND ";

                if( !empty($excludeTypes) ){
                    $whereQuery .= " `connection`.`type` NOT REGEXP '" . implode("|", $excludeTypes) . "' AND ";
                }

                if( !empty($includeTypes) ){
                    $whereQuery .= " `connection`.`type` REGEXP '" . implode("|", $includeTypes) . "' AND ";
                }

                if(!$includeEOL){
                    $whereQuery .= " `connection`.`eolUpdated` IS NULL AND ";
                }

                $query = "SELECT
                        `system_src`.`regionId` regionId,
                        `system_src`.`constellationId` constellationId,
                        `system_src`.`name` systemName,
                        `system_src`.`systemId` systemId,
                        (
                          SELECT
                            GROUP_CONCAT( NULLIF(`system_tar`.`name`, NULL) SEPARATOR ':')
                          FROM
                            `connection` INNER JOIN
                            `system` system_tar ON
                              `system_tar`.`id` = `connection`.`source` OR
                              `system_tar`.`id` = `connection`.`target`
                          WHERE
                            `connection`.`mapId` " . $whereMapIdsQuery . " AND
                            `connection`.`active` = 1 AND
                            (
                              `connection`.`source` = `system_src`.`id` OR
                              `connection`.`target` = `system_src`.`id`
                            ) AND
                            " . $whereQuery . "
                            `system_tar`.`id` != `system_src`.`id` AND
                            `system_tar`.`active` = 1
                        ) jumpNodes,
                        `system_src`.`trueSec` trueSec
                    FROM
                        `system` `system_src` INNER JOIN
                        `map` ON
                          `map`.`id` = `system_src`.`mapId`
                    WHERE
                        `system_src`.`mapId` " . $whereMapIdsQuery . " AND
                        `system_src`.`active` = 1 AND
                        `map`.`active` = 1
                    HAVING
                        -- skip systems without neighbors (e.g. WHs)
	                    jumpNodes IS NOT NULL
                ";

                $rows = $this->getDB()->exec($query,  null, $this->dynamicJumpDataCacheTime);

                if(count($rows) > 0){
                    // update jump data for this instance
                    $this->updateJumpData($rows);
                }

            }
        }
    }

    /**
     * update jump data for this instance
     * -> data is either coming from CCPs [SDE] OR from map specific data
     * @param array $rows
     */
    private function updateJumpData(&$rows = []){

        foreach($rows as &$row){
            $regionId       = (int)$row['regionId'];
            $constId        = (int)$row['constellationId'];
            $systemName     = strtoupper($row['systemName']);
            $systemId       = (int)$row['systemId'];
            $secStatus      = (float)$row['trueSec'];

            // fill "nameArray" data ----------------------------------------------------------------------------------
            if( !isset($this->nameArray[$systemId]) ){
                $this->nameArray[$systemId][0] = $systemName;
                $this->nameArray[$systemId][1] = $regionId;
                $this->nameArray[$systemId][2] = $constId;
                $this->nameArray[$systemId][3] = $secStatus;
            }

            // fill "idArray" data ------------------------------------------------------------------------------------
            if( !isset($this->idArray[$systemName]) ){
                $this->idArray[$systemName] = $systemId;
            }

            // fill "jumpArray" data ----------------------------------------------------------------------------------
            if( !is_array($this->jumpArray[$systemName]) ){
                $this->jumpArray[$systemName] = [];
            }
            $this->jumpArray[$systemName] = array_merge( explode(':', strtoupper($row['jumpNodes'])), $this->jumpArray[$systemName] );

            // add systemId to end (if not already there)
            if(end($this->jumpArray[$systemName]) != $systemId){
                array_push($this->jumpArray[$systemName],$systemId);
            }
        }
    }

    /**
     * filter systems (remove some systems) e.g. WH,LS,0.0 for "secure search"
     * @param array $filterData
     * @param array $keepSystems
     */
    private function filterJumpData($filterData = [], $keepSystems = []){
        if($filterData['flag'] == 'secure'){
            // remove all systems (TrueSec < 0.5) from search arrays
            $this->jumpArray = array_filter($this->jumpArray, function($jumpData) use($keepSystems) {

                // systemId is always last entry
                $systemId = end($jumpData);
                $systemNameData = $this->nameArray[$systemId];
                $systemSec = $systemNameData[3];

                if($systemSec < 0.45 && !in_array($systemId, $keepSystems)){
                    // remove system from nameArray as well
                    unset($this->nameArray[$systemId]);
                    // remove system from idArray as well
                    $systemName = $systemNameData[0];
                    unset($this->idArray[$systemName]);
                    return false;
                }else{
                    return true;
                }
            });
        }
    }

    /**
     * get system data by systemId and dataName
     * @param $systemId
     * @param $option
     * @return null
     */
    private function getSystemInfoBySystemId($systemId, $option){
        $info = null;
        switch($option){
            case 'systemName':
                $info = $this->nameArray[ $systemId ][0];
                break;
            case 'regionId':
                $info = $this->nameArray[ $systemId ][1];
                break;
            case 'constellationId':
                $info = $this->nameArray[ $systemId ][2];
                break;
            case 'trueSec':
                $info = $this->nameArray[ $systemId ][3];
                break;
        }

        return $info;
    }

    /**
     * recursive search function within a undirected graph
     * @param $G
     * @param $A
     * @param $B
     * @param int $M
     * @return array
     */
    private function graph_find_path(&$G, $A, $B, $M = 50000){
        $maxDepth = $M;

        // $P will hold the result path at the end.
        // Remains empty if no path was found.
        $P = [];

        // For each Node ID create a "visit information",
        // initially set as 0 (meaning not yet visited)
        // as soon as we visit a node we will tag it with the "source"
        // so we can track the path when we reach the search target

        $V = [];

        // We are going to keep a list of nodes that are "within reach",
        // initially this list will only contain the start node,
        // then gradually expand (almost like a flood fill)
        $R = [trim($A)];

        $A = trim($A);
        $B = trim($B);

        while(count($R) > 0 && $M > 0){
            $M--;

            $X = trim(array_shift($R));

            if(array_key_exists($X, $G)){
                foreach($G[$X] as $Y){
                    $Y = trim($Y);
                    // See if we got a solution
                    if($Y == $B){
                        // We did? Construct a result path then
                        array_push($P, $B);
                        array_push($P, $X);
                        while($V[$X] != $A){
                            array_push($P, trim($V[$X]));
                            $X = $V[$X];
                        }
                        array_push($P, $A);
                        //return array_reverse($P);
                        return [
                            'path'=> array_reverse($P),
                            'depth' => ($maxDepth - $M)
                        ];
                    }
                    // First time we visit this node?
                    if(!array_key_exists($Y, $V)){
                        // Store the path so we can track it back,
                        $V[$Y] = $X;
                        // and add it to the "within reach" list
                        array_push($R, $Y);
                    }
                }
            }
        }

        return [
            'path'=> $P,
            'depth' => ($maxDepth - $M)
        ];
    }

    /**
     * get formatted jump node data
     * @param $systemName
     * @return array
     */
    protected function getJumpNodeData($systemName) : array {
        return [
            'system' => $systemName,
            'security' => $this->getSystemInfoBySystemId($this->idArray[$systemName], 'trueSec')
        ];
    }

    /**
     * search root between two systemIds
     * -> function searches over ESI API, as fallback a custom search algorithm is used (no ESI)
     * @param int $systemFromId
     * @param int $systemToId
     * @param int $searchDepth
     * @param array $mapIds
     * @param array $filterData
     * @return array
     * @throws \Exception\PathfinderException
     */
    public function searchRoute(int $systemFromId, int $systemToId, $searchDepth = 0, array $mapIds = [], array $filterData = []) : array {
        // search root by ESI API
        $routeData = $this->searchRouteESI($systemFromId, $systemToId, $searchDepth, $mapIds, $filterData);

        if( !empty($routeData['error']) ){
            // ESI route search has errors -> fallback to custom search implementation
            $routeData = $this->searchRouteCustom($systemFromId, $systemToId, $searchDepth, $mapIds, $filterData);
        }

        return $routeData;
    }

    /**
     * uses a custom search algorithm to fine a route
     * @param int $systemFromId
     * @param int $systemToId
     * @param int $searchDepth
     * @param array $mapIds
     * @param array $filterData
     * @return array
     * @throws \Exception\PathfinderException
     */
    public function searchRouteCustom(int $systemFromId, int $systemToId, $searchDepth = 0, array $mapIds = [], array $filterData = []) : array {
        // reset all previous set jump data
        $this->resetJumpData();

        $searchDepth = $searchDepth ? $searchDepth : Config::getPathfinderData('route.search_depth');

        $routeData = $this->defaultRouteData;
        $routeData['maxDepth'] = $searchDepth;
        $routeData['searchType'] = 'custom';

        if($systemFromId && $systemToId){
            // prepare search data ------------------------------------------------------------------------------------
            // add static data (e.g. K-Space stargates,..)
            $this->setStaticJumpData();

            // add map specific data
            $this->setDynamicJumpData($mapIds, $filterData);

            // filter jump data (e.g. remove some systems (0.0, LS)
            // --> don´t filter some systems (e.g. systemFrom, systemTo) even if they are are WH,LS,0.0
            $this->filterJumpData($filterData, [$systemFromId, $systemToId]);

            $systemFrom = $this->getSystemInfoBySystemId($systemFromId, 'systemName');
            $systemTo = $this->getSystemInfoBySystemId($systemToId, 'systemName');

            // search route -------------------------------------------------------------------------------------------

            // jump counter
            $jumpNum = 0;
            $depthSearched = 0;

            if( isset($this->jumpArray[$systemFrom]) ){
                // check if the system we are looking for is a direct neighbour
                foreach( $this->jumpArray[$systemFrom] as $n ) {
                    if ($n == $systemTo) {
                        $jumpNum = 2;
                        $routeData['route'][] = $this->getJumpNodeData($n);
                        break;
                    }
                }

                // system is not a direct neighbour -> search recursive its neighbours
                if ($jumpNum == 0) {
                    $searchResult = $this->graph_find_path( $this->jumpArray, $systemFrom, $systemTo, $searchDepth );
                    $depthSearched = $searchResult['depth'];
                    foreach( $searchResult['path'] as $systemName ) {
                        if ($jumpNum > 0) {
                            $routeData['route'][] = $this->getJumpNodeData($systemName);
                        }
                        $jumpNum++;
                    }
                }

                if ($jumpNum > 0) {
                    // route found
                    $routeData['routePossible'] = true;
                    // insert "from" system on top
                    array_unshift($routeData['route'], $this->getJumpNodeData($systemFrom));
                } else {
                    // route not found
                    $routeData['routePossible'] = false;
                }
            }

            // route jumps
            $routeData['routeJumps'] = $jumpNum - 1;
            $routeData['depthSearched'] = $depthSearched;
        }

        return $routeData;
    }

    /**
     * uses ESI route search endpoint to fine a route
     * @param int $systemFromId
     * @param int $systemToId
     * @param int $searchDepth
     * @param array $mapIds
     * @param array $filterData
     * @return array
     * @throws \Exception\PathfinderException
     */
    public function searchRouteESI(int $systemFromId, int $systemToId, int $searchDepth = 0, array $mapIds = [], array $filterData = []) : array {
        // reset all previous set jump data
        $this->resetJumpData();

        $searchDepth = $searchDepth ? $searchDepth : Config::getPathfinderData('route.search_depth');

        $routeData = $this->defaultRouteData;
        $routeData['maxDepth'] = $searchDepth;
        $routeData['searchType'] = 'esi';

        if($systemFromId && $systemToId){
            // prepare search data ------------------------------------------------------------------------------------

            // add map specific data
            $this->setDynamicJumpData($mapIds, $filterData);

            // filter jump data (e.g. remove some systems (0.0, LS)
            // --> don´t filter some systems (e.g. systemFrom, systemTo) even if they are are WH,LS,0.0
            $this->filterJumpData($filterData, [$systemFromId, $systemToId]);

            $connections = [];
            foreach($this->jumpArray as $systemSourceName => $jumpData){
                $count = count($jumpData);
                if($count > 1){
                    // ... should always > 1
                    $systemSourceId = (int)$this->idArray[$systemSourceName];
                    // loop all connections for current source system
                    foreach($jumpData as $systemTargetName) {
                        // skip last entry
                        if(--$count <= 0){
                            break;
                        }
                        $systemTargetId = (int)$this->idArray[$systemTargetName];

                        // systemIds exist and wer not removed before in filterJumpData()
                        if($systemSourceId && $systemTargetId){
                            $connections[] = [$systemSourceId, $systemTargetId];
                        }
                    }
                }
            }

            // search route -------------------------------------------------------------------------------------------
            $options = [
                'flag' => $filterData['flag'],
                'connections' => $connections
            ];

            $result = $this->getF3()->ccpClient->getRouteData($systemFromId, $systemToId, $options);

            // format result ------------------------------------------------------------------------------------------

            // jump counter
            $jumpNum = 0;
            $depthSearched = 0;
            if( !empty($result['error']) ){
                $routeData['error'] = $result['error'];
            }elseif( !empty($result['route']) ){
                $jumpNum = count($result['route']) - 1;

                // check max search depth
                if($jumpNum <= $routeData['maxDepth']){
                    $depthSearched = $jumpNum;

                    $routeData['routePossible'] = true;

                    // Now (after search) we have to "add" static jump data information
                    $this->setStaticJumpData();

                    foreach($result['route'] as $systemId){
                        $systemName = $this->getSystemInfoBySystemId($systemId, 'systemName');
                        $routeData['route'][] = $this->getJumpNodeData($systemName);
                    }
                }else{
                    $depthSearched = $routeData['maxDepth'];
                }
            }

            // route jumps
            $routeData['routeJumps'] = $jumpNum;
            $routeData['depthSearched'] = $depthSearched;
        }

        return $routeData;
    }

    /**
     * get key for route cache
     * @param $mapIds
     * @param $systemFrom
     * @param $systemTo
     * @param array $filterData
     * @return string
     */
    private function getRouteCacheKey($mapIds, $systemFrom, $systemTo, $filterData = []){

        $keyParts = [
            implode('_', $mapIds),
            self::formatHiveKey($systemFrom),
            self::formatHiveKey($systemTo)
        ];

        $keyParts += $filterData;
        $key = 'route_' . hash('md5', implode('_', $keyParts));

        return $key;
    }

    /**
     * search multiple route between two systems
     * @param \Base $f3
     * @throws \Exception
     * @throws \Exception\PathfinderException
     */
    public function search($f3){
        $requestData = (array)$f3->get('POST');

        $activeCharacter = $this->getCharacter();

        $return = (object) [];
        $return->error = [];
        $return->routesData = [];

        if( !empty($requestData['routeData']) ){
            $routesData = (array)$requestData['routeData'];

            //  map data where access was already checked -> cached data
            $validMaps = [];

            /**
             * @var $map Model\MapModel
             */
            $map = Model\BasicModel::getNew('MapModel');

            // limit max search routes to max limit
            array_splice($routesData, Config::getPathfinderData('route.limit'));

            foreach($routesData as $key => $routeData){
                // mapIds are optional. If mapIds is empty or not set
                // route search is limited to CCPs static data
                $mapData = (array)$routeData['mapIds'];
                $mapData = array_flip( array_map('intval', $mapData) );

                // check map access (filter requested mapIDs and format) ----------------------------------------------
                array_walk($mapData, function(&$item, &$key, $data){
                    /**
                     * @var Model\MapModel $data[0]
                     */
                    if( isset($data[1][$key]) ){
                        // character has map access -> do not check again
                        $item = $data[1][$key];
                    }else{
                        // check map access for current character
                        $data[0]->getById($key);

                        if( $data[0]->hasAccess($data[2]) ){
                            $item = ['id' => $key, 'name' => $data[0]->name];
                        }else{
                            $item = false;
                        }
                        $data[0]->reset();
                    }

                }, [$map, $validMaps, $activeCharacter]);

                // filter maps with NO access right
                $mapData = array_filter($mapData);
                $mapIds = array_column($mapData, 'id');

                // add map data to cache array
                $validMaps += $mapData;

                // search route with filter options
                $filterData = [
                    'stargates' => (bool) $routeData['stargates'],
                    'jumpbridges' => (bool) $routeData['jumpbridges'],
                    'wormholes' => (bool) $routeData['wormholes'],
                    'wormholesReduced' => (bool) $routeData['wormholesReduced'],
                    'wormholesCritical' => (bool) $routeData['wormholesCritical'],
                    'wormholesFrigate' => (bool) $routeData['wormholesFrigate'],
                    'wormholesEOL' => (bool) $routeData['wormholesEOL'],
                    'flag' => $routeData['flag']
                ];

                $returnRoutData = [
                    'systemFromData' => $routeData['systemFromData'],
                    'systemToData' => $routeData['systemToData'],
                    'skipSearch' => (bool) $routeData['skipSearch'],
                    'maps' => $mapData,
                    'mapIds' => $mapIds
                ];

                // add filter options for each route as well
                $returnRoutData += $filterData;

                if(
                    !$returnRoutData['skipSearch'] &&
                    count($mapIds) > 0
                ){
                    $systemFrom = $routeData['systemFromData']['name'];
                    $systemFromId = (int)$routeData['systemFromData']['systemId'];
                    $systemTo = $routeData['systemToData']['name'];
                    $systemToId = (int)$routeData['systemToData']['systemId'];

                    $cacheKey = $this->getRouteCacheKey(
                        $mapIds,
                        $systemFrom,
                        $systemTo,
                        $filterData
                    );

                    if($f3->exists($cacheKey, $cachedData)){
                        // get data from cache
                        $returnRoutData = $cachedData;
                    }else{
                        $foundRoutData = $this->searchRoute($systemFromId, $systemToId, 0, $mapIds, $filterData);

                        $returnRoutData = array_merge($returnRoutData, $foundRoutData);

                        // cache if route was found
                        if(
                            isset($returnRoutData['routePossible']) &&
                            $returnRoutData['routePossible'] === true
                        ){
                            $f3->set($cacheKey, $returnRoutData, $this->dynamicJumpDataCacheTime);
                        }
                    }
                }

                $return->routesData[] = $returnRoutData;
            }
        }

        echo json_encode($return);
    }


}













