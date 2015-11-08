<?php
/**
* Php proxy to access map services
* @package   lizmap
* @subpackage lizmap
* @author    3liz
* @copyright 2011 3liz
* @link      http://3liz.com
* @license Mozilla Public License : http://www.mozilla.org/MPL/
*/

class serviceCtrl extends jController {


  protected $project = '';
  protected $repository = '';
  protected $services = '';
  protected $params = '';
  protected $lizmapCache = '';


  /**
  * Redirect to the appropriate action depending on the REQUEST parameter.
  * @param $PROJECT Name of the project
  * @param $REQUEST Request type
  * @return Redirect to the corresponding action depending on the request parameters
  */
  function index() {
    if (isset($_SERVER['PHP_AUTH_USER'])) {
      $ok = jAuth::login($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    }

    $rep = $this->getResponse('redirect');

    // Get the project
    $project = $this->iParam('project');
    if(!$project){
      // Error message
      jMessage::add('The parameter project is mandatory !', 'ProjectNotDefind');
      return $this->serviceException();
    }

    // Return the appropriate action
    $request = strtoupper($this->iParam('REQUEST'));
    if($request == "GETCAPABILITIES")
      return $this->getCapabilities();
    elseif ($request == "GETCONTEXT")
      return $this->GetContext();
    elseif ($request == "GETSCHEMAEXTENSION")
      return $this->GetSchemaExtension();
    elseif ($request == "GETLEGENDGRAPHICS")
      return $this->GetLegendGraphics();
    elseif ($request == "GETLEGENDGRAPHIC")
      return $this->GetLegendGraphics();
    elseif ($request == "GETFEATUREINFO")
      return $this->GetFeatureInfo();
    elseif ($request == "GETPRINT")
      return $this->GetPrint();
    elseif ($request == "GETSTYLES")
      return $this->GetStyles();
    elseif ($request == "GETMAP")
      return $this->GetMap();
    elseif ($request == "GETFEATURE")
      return $this->GetFeature();
    elseif ($request == "DESCRIBEFEATURETYPE")
      return $this->DescribeFeatureType();
    elseif ($request == "GETPROJ4")
      return $this->GetProj4();
    else {
      global $HTTP_RAW_POST_DATA;
      if(isset($HTTP_RAW_POST_DATA)){
        $requestXml = $HTTP_RAW_POST_DATA;
      }else{
        $requestXml = file('php://input');
        $requestXml = implode("\n",$requestXml);
      }
      $xml = simplexml_load_string( $requestXml );
      if($xml == false){
        jMessage::add('REQUEST '.$request.' not supported by Lizmap Web Client', 'InvalidRequest');
        return $this->serviceException();
      }
      return $this->PostRequest( $requestXml );
    }
  }


  /**
  * Get a request parameter
  * whatever its case
  * and returns its value.
  * @param $param request parameter.
  * @return Request parameter value.
  */
  protected function iParam($param){

    $pParams = jApp::coord()->request->params;
    foreach($pParams as $k=>$v){
      if(strtolower($k) == strtolower($param)){
        return $v;
      }
    }
    return Null;
  }

  /**
  * Send an OGC service Exception
  * @param $SERVICE the OGC service
  * @return XML OGC Service Exception.
  */
  function serviceException(){
    $messages = jMessage::getAll();

    $rep = $this->getResponse('xml');
    $rep->contentTpl = 'lizmap~wms_exception';
    $rep->content->assign('messages', $messages);
    jMessage::clearAll();

    return $rep;
  }


  /**
  * Get parameters and set classes for the project and repository given.
  *
  * @return array List of needed variables : $params, $lizmapProject, $lizmapRepository, $lizmapCache.
  */
  protected function getServiceParameters(){

    // Get the project
    $project = $this->iParam('project');

    if(!$project){
      jMessage::add('The parameter project is mandatory !', 'ProjectNotDefind');
      return false;
    }

    // Get repository data
    $repository = $this->iParam('repository');

    // Get the corresponding repository
    $lrep = lizmap::getRepository($repository);

    // Redirect if no rights to access this repository
    if(!jacl2::check('lizmap.repositories.view', $lrep->getKey())){
      jMessage::add(jLocale::get('view~default.repository.access.denied'), 'AuthorizationRequired');
      return false;
    }

    // Get and normalize the passed parameters
    $pParams = jApp::coord()->request->params;
    $pParams['map'] = realpath($lrep->getPath()) . '/' . $project . ".qgs";
    $lizmapCache = jClasses::getService('lizmap~lizmapCache');
    $params = $lizmapCache->normalizeParams($pParams);

    // Define class private properties
    $this->project = lizmap::getProject($repository.'~'.$project);
    $this->repository = $lrep;
    $this->services = lizmap::getServices();
    $this->params = $params;
    $this->lizmapCache = $lizmapCache;

    // Optionnaly filter data by login
    if(isset($params['request'])){
      $request = strtolower($params['request']);
      if(
        in_array($request, array('getmap', 'getfeatureinfo', 'getfeature', 'getprint'))
        and !jacl2::check('lizmap.tools.loginFilteredLayers.override', $lrep->getKey() )
      ){
        $this->filterDataByLogin();
      }
    }

    return true;
  }

  /**
  * Filter data by login if necessary
  * as configured in the plugin for login filtered layers.
  */
  protected function filterDataByLogin() {

    // Optionnaly add a filter parameter
    $lproj = lizmap::getProject($this->repository->getKey().'~'.$this->project->getKey());

    $request = strtolower($this->params['request']);
    if( $request == 'getfeature' )
      $layers = $this->params["typename"];
    else
      $layers = $this->params["layers"];
    $pConfig = $lproj->getFullCfg();

    // Filter only if needed
    if( $lproj->hasLoginFilteredLayers()
      and $pConfig->loginFilteredLayers
    ){
      $v='';
      $filter='';
      foreach(explode(',', $layers) as $layername){
        if( property_exists($pConfig->loginFilteredLayers, $layername) ) {
          $oAttribute = $pConfig->loginFilteredLayers->$layername->filterAttribute;
          $attribute = strtolower($oAttribute);

          // Check if a user is authenticated
          $isConnected = jAuth::isConnected();
          $pre = "$layername:";
          if($request == 'getfeature')
            $pre = '';
          if($isConnected){
            $user = jAuth::getUserSession();
            $login = $user->login;
            if (property_exists($pConfig->loginFilteredLayers->$layername, 'filterPrivate')
             && $pConfig->loginFilteredLayers->$layername->filterPrivate = 'True')
            {
              $filter.= $v."$pre\"$attribute\" IN ( '".$login."' , 'all' )";
		    } else {
              $userGroups = jAcl2DbUserGroup::getGroups();
              $flatGroups = implode("' , '", $userGroups);
              $filter.= $v."$pre\"$attribute\" IN ( '".$flatGroups."' , 'all' )";
		    }
            $v = ';';
          }else{
            // The user is not authenticated: only show data with attribute = 'all'
            $filter.= $v."$pre\"$attribute\" = 'all'";
            $v = ';';
          }
        }
      }

      // Set filter when multiple layers concerned
      if($filter)
        if( $request == 'getfeature' ){
          $this->params['exp_filter'] = $filter;
          if( array_key_exists('propertyname', $this->params)  ){
            $propertyName = trim($this->params["propertyname"]);
            if( !empty($propertyName) )
         	$this->params["propertyname"].= ",$oAttribute";
          }
        }
        else
          $this->params['filter'] = $filter;
    }
  }


  /**
  * GetCapabilities
  * @param string $repository Lizmap Repository
  * @param string $project Name of the project : mandatory.
  * @return JSON configuration file for the specified project.
  */
  function GetCapabilities(){

    // Get parameters
    if(!$this->getServiceParameters())
      return $this->serviceException();

    $cacheId = $this->repository->getKey().'_'.$this->project->getKey().'_'.$this->params['service'];
    $hash = jCache::get($cacheId . '_hash');

    $cachedMime = jCache::get($cacheId . '_mime');
    $cachedData = jCache::get($cacheId . '_data');
    $newhash = md5_file( realpath($this->repository->getPath()) . '/' . $this->project->getKey() . ".qgs" );
    
    // Verifying cache content
    if ( $cachedData && preg_match( '#ServiceExceptionReport#i', $cachedData ) ) {
        $hash = null;
        $cachedMime = null;
        $cachedData = null;
        jCache::delete($cacheId . '_hash');
    }

    // Cache exists for the unchanged QGIS project file
    if( $hash and $cachedMime and $cachedData and $hash == $newhash ) {
        $mime = $cachedMime;
        $data = $cachedData;
    }

    // No cache or the file content has changed
    else {

        $url = $this->services->wmsServerURL.'?';

        $bparams = http_build_query($this->params);
        $querystring = $url . $bparams;

        // Get remote data
        $getRemoteData = $this->lizmapCache->getRemoteData(
          $querystring,
          $this->services->proxyMethod,
          $this->services->debugMode
        );
        $data = $getRemoteData[0];
        $mime = $getRemoteData[1];
        $code = $getRemoteData[2];
        
        if ( empty( $data ) or floor( $code / 100 ) >= 4 ) {
            jMessage::add('Server Error !', 'Error');
            return $this->serviceException();
        }
        
        if ( preg_match( '#ServiceExceptionReport#i', $data ) ) {
            $rep = $this->getResponse('binary');
            $rep->mimeType = $mime;
            $rep->content = $data;
            $rep->doDownload = false;
            $rep->outputFileName  =  'qgis_server_exception_report';

            return $rep;
        }

        // Replace qgis server url in the XML (hide real location)
        $sUrl = jUrl::getFull(
          "lizmap~service:index",
          array("repository"=>$this->repository->getKey(), "project"=>$this->project->getKey()),
          0,
          $_SERVER['SERVER_NAME']
        );
        $sUrl = str_replace('&', '&amp;', $sUrl.'&');
        preg_match('/<get>.*\n*.+xlink\:href="(.+)"/i', $data, $matches);
        if ( count( $matches ) < 2 )
            preg_match('/get onlineresource="(.+)"/i', $data, $matches);
        if ( count( $matches ) > 1 )
            $data = str_replace($matches[1], $sUrl, $data);
        $data = str_replace('&amp;&amp;', '&amp;', $data);

        // Remove no interoparable elements
        $data = preg_replace('@<GetPrint[^>]*?>.*?</GetPrint>@si', '', $data);
        $data = preg_replace('@<ComposerTemplates[^>]*?>.*?</ComposerTemplates>@si', '', $data);

        if ( preg_match( '@WMS_Capabilities@i', $data) ) {
            // Update namespace
            $schemaLocation = "http://www.opengis.net/wms";
            $schemaLocation .= " http://schemas.opengis.net/wms/1.3.0/capabilities_1_3_0.xsd";
            $schemaLocation .= " http://www.opengis.net/sld";
            $schemaLocation .= " http://schemas.opengis.net/sld/1.1.0/sld_capabilities.xsd";
            $schemaLocation .= " http://www.qgis.org/wms";
            $schemaLocation .= " ". $sUrl ."SERVICE=WMS&amp;REQUEST=GetSchemaExtension";
            $data = preg_replace('@xsi:schemaLocation=".*?"@si', 'xsi:schemaLocation="'.$schemaLocation.'"', $data);
            if ( !preg_match( '@xmlns:qgs@i', $data) ) {
              $data = preg_replace('@xmlns="http://www.opengis.net/wms"@', 'xmlns="http://www.opengis.net/wms" xmlns:qgs="http://www.qgis.org/wms"', $data);
              $data = preg_replace('@GetStyles@', 'qgs:GetStyles', $data);
            }
            if ( !preg_match( '@xmlns:sld@i', $data) ) {
              $data = preg_replace('@xmlns="http://www.opengis.net/wms"@', 'xmlns="http://www.opengis.net/wms" xmlns:sld="http://www.opengis.net/sld"', $data);
              $data = preg_replace('@GetLegendGraphic@', 'sld:GetLegendGraphic', $data);
            }
        }

        // Add response to cache
        jCache::set($cacheId . '_hash', $newhash);
        jCache::set($cacheId . '_mime', $mime);
        jCache::set($cacheId . '_data', $data);

    }

    // Return response
    $rep = $this->getResponse('binary');
    $rep->mimeType = $mime;
    $rep->content = $data;
    $rep->doDownload = false;
    $rep->outputFileName  =  'qgis_server_getCapabilities';

    return $rep;
  }

  /**
  * GetContext
  * @param string $repository Lizmap Repository
  * @param string $project Name of the project : mandatory.
  * @return text/xml Web Map Context.
  */
  function GetContext(){

    // Get parameters
    if(!$this->getServiceParameters())
      return $this->serviceException();

    $url = $this->services->wmsServerURL.'?';

    $bparams = http_build_query($this->params);
    $querystring = $url . $bparams;

    // Get remote data
    $getRemoteData = $this->lizmapCache->getRemoteData(
      $querystring,
      $this->services->proxyMethod,
      $this->services->debugMode
    );
    $data = $getRemoteData[0];
    $mime = $getRemoteData[1];

    // Replace qgis server url in the XML (hide real location)
    $sUrl = jUrl::getFull(
      "lizmap~service:index",
      array("repository"=>$this->repository->getKey(), "project"=>$this->project->getKey()),
      0,
      $_SERVER['SERVER_NAME']
    );
    $sUrl = str_replace('&', '&amp;', $sUrl);
    $data = preg_replace('/xlink\:href=".*"/', 'xlink:href="'.$sUrl.'&amp;"', $data);

    // Return response
    $rep = $this->getResponse('binary');
    $rep->mimeType = $mime;
    $rep->content = $data;
    $rep->doDownload = false;
    $rep->outputFileName  =  'qgis_server_getContext';

    return $rep;
  }

  /**
  * GetSchemaExtension
  * @param string $SERVICE mandatory, has to be WMS
  * @param string $REQUEST mandatory, has to be GetSchemaExtension
  * @return text/xml the WMS GEtCapabilities 1.3.0 Schema Extension.
  */
  function GetSchemaExtension(){
    $data = '<?xml version="1.0" encoding="UTF-8"?>
<schema xmlns="http://www.w3.org/2001/XMLSchema" xmlns:wms="http://www.opengis.net/wms" xmlns:qgs="http://www.qgis.org/wms" targetNamespace="http://www.qgis.org/wms" elementFormDefault="qualified" version="1.0.0">
  <import namespace="http://www.opengis.net/wms" schemaLocation="http://schemas.opengis.net/wms/1.3.0/capabilities_1_3_0.xsd"/>
  <element name="GetPrint" type="wms:OperationType" substitutionGroup="wms:_ExtendedOperation" />
  <element name="GetStyles" type="wms:OperationType" substitutionGroup="wms:_ExtendedOperation" />
</schema>';
    // Return response
    $rep = $this->getResponse('binary');
    $rep->mimeType = 'text/xml';
    $rep->content = $data;
    $rep->doDownload = false;
    $rep->outputFileName  =  'qgis_server_schema_extension';
    return $rep;
  }

  /**
  * GetMap
  * @param string $repository Lizmap Repository
  * @param string $project Name of the project : mandatory
  * @return Image rendered by the Map Server.
  */
  function GetMap(){

    // Get parameters
    if(!$this->getServiceParameters())
      return $this->serviceException();

    $content = $this->lizmapCache->getServiceData($this->repository->getKey(), $this->project->getKey(), $this->params);

    // Return response
    $rep = $this->getResponse('binary');
    if(preg_match('#png#', $this->params['format']))
      $rep->mimeType = 'image/png';
    else
      $rep->mimeType = 'image/jpeg';
    $rep->content = $content;
    $rep->doDownload  =  false;
    $rep->outputFileName  =  'qgis_server';

    // HTTP browser cache expiration time
    $layername = $this->params["layers"];
    $lproj = lizmap::getProject($this->repository->getKey().'~'.$this->project->getKey());
    $configLayers = $lproj->getLayers();
    if( property_exists($configLayers, $layername) ){
      $configLayer = $configLayers->$layername;
      if( property_exists($configLayer, 'clientCacheExpiration')){
        $clientCacheExpiration = (int)$configLayer->clientCacheExpiration;
        $rep->setExpires("+".$clientCacheExpiration." seconds");
      }
    }

    return $rep;
  }


  /**
  * GetLegendGraphics
  * @param string $repository Lizmap Repository
  * @param string $project Name of the project : mandatory
  * @return Image of the legend for 1 to n layers, returned by the Map Server
  */
  function GetLegendGraphics(){

    // Get parameters
    if(!$this->getServiceParameters())
      return $this->serviceException();

    $url = $this->services->wmsServerURL.'?';
    $bparams = http_build_query($this->params);
    // replace some chars (not needed in php 5.4, use the 4th parameter of http_build_query)
    $a = array('+', '_', '.', '-');
    $b = array('%20', '%5F', '%2E', '%2D');
    $bparams = str_replace($a, $b, $bparams);
    $querystring = $url . $bparams;

    // Get remote data
    $getRemoteData = $this->lizmapCache->getRemoteData(
      $querystring,
      $this->services->proxyMethod,
      $this->services->debugMode
    );
    $data = $getRemoteData[0];
    $mime = $getRemoteData[1];

    $rep = $this->getResponse('binary');
    $rep->mimeType = $mime;
    $rep->content = $data;
    $rep->doDownload = false;
    $rep->outputFileName  =  'qgis_server_legend';

    return $rep;
  }


  /**
  * GetFeatureInfo
  * @param string $repository Lizmap Repository
  * @param string $project Name of the project : mandatory
  * @return Feature Info.
  */
  function GetFeatureInfo(){

    // Get parameters
    if(!$this->getServiceParameters())
      return $this->serviceException();

    $url = $this->services->wmsServerURL.'?';

    // Deactivate info_format to use Lizmap instead of QGIS
    $toHtml = False;
    if($this->params['info_format'] == 'text/html'){
      $toHtml = True;
      $this->params['info_format'] = 'text/xml';
    }

    $bparams = http_build_query($this->params);
    $querystring = $url . $bparams;

    // Get remote data
    $getRemoteData = $this->lizmapCache->getRemoteData(
      $querystring,
      $this->services->proxyMethod,
      $this->services->debugMode
    );
    $data = $getRemoteData[0];
    $mime = $getRemoteData[1];

    // Get HTML content if needed
    if($toHtml and preg_match('#/xml#', $mime)){
      $data = $this->getFeatureInfoHtml($this->params, $data);
      $mime = 'text/html';
    }

   // Log
   $eventParams = array(
    'key' => 'popup',
    'content' => '',
    'repository' => $this->repository->getKey(),
    'project' => $this->project->getKey()
   );
   jEvent::notify('LizLogItem', $eventParams);

    $rep = $this->getResponse('binary');
    $rep->mimeType = $mime;
    $rep->content = $data;
    $rep->doDownload = false;
    $rep->outputFileName = 'getFeatureInfo';

    return $rep;
  }

  /**
  * replaceMediaPathByMediaUrl : replace all "/media/bla" in a text by the getMedia corresponding URL.
  * This method is used as callback in GetFeatureInfoHtml method for the preg_replace_callback
  * @param array $matches Array containing the preg matches
  * @return Replaced text.
  */
  function replaceMediaPathByMediaUrl($matches){
    $return = '';
    $return.= '"';
    $return.= jUrl::getFull(
      'view~media:getMedia',
      array(
        'repository'=>$this->repository->getKey(),
        'project'=>$this->project->getKey(),
        'path'=>$matches[2]
      ),
      0,
      $_SERVER['SERVER_NAME']
    );
    $return.= '"';
    return $return;
  }


  /**
  * GetFeatureInfoHtml : return HTML for the getFeatureInfo.
  * @param array $params Array of parameters
  * @param string $xmldata XML data from getFeatureInfo
  * @return Feature Info in HTML format.
  */
  function getFeatureInfoHtml($params, $xmldata){

    // Get data from XML
    $use_errors = libxml_use_internal_errors(true);
    $go = true; $errorlist = array();
    // Create a DOM instance
    $xml = simplexml_load_string($xmldata);
    if(!$xml) {
      foreach(libxml_get_errors() as $error) {
        $errorlist[] = $error;
      }
      $go = false;
    }

    // Get json configuration for the project
    $configLayers = $this->project->getLayers();

    // Loop through the layers
    $content = array();
    $ptemplate = 'view~popup';
    $lizmapCache = $this->lizmapCache;
    $popupClass = jClasses::getService('view~popup');

    foreach($xml->Layer as $layer){
      $layername = $layer['name'];
      $configLayer = $this->project->findLayerByName( $layername );
      // since 2.6 layer's name can be layer's title
      if ( $configLayer == null )
        $configLayer = $this->project->findLayerByTitle( $layername );
      if ( $configLayer == null )
        continue;

      // Avoid layer if no popup asked by the user for it
      // or if no popup property
      if(property_exists($configLayer, 'popup')){
        if($configLayer->popup != 'True'){
          continue;
        }
      }
      else{
        continue;
      }

      // Get layer title
      $layerTitle = $configLayer->title;

      // Get the template for the popup content
      $templateConfigured = False;
      if(property_exists($configLayer, 'popupTemplate')){
        // Get template content
        $popupTemplate = (string)trim($configLayer->popupTemplate);
        // Use it if not empty
        if(!empty($popupTemplate)){
          $templateConfigured = True;
          // first replace all "media/bla/bla/llkjk.ext" by full url
          $popupTemplate = preg_replace_callback(
            '#(["\']){1}(media/.+\.\w{3,10})(["\']){1}#',
            Array($this, 'replaceMediaPathByMediaUrl'),
            $popupTemplate
          );
          // Replace : html encoded chars to let further regexp_replace find attributes
          $popupTemplate = str_replace(array('%24', '%7B', '%7D'), array('$', '{', '}'), $popupTemplate);
        }
      }

      // Loop through the features
      foreach($layer->Feature as $feature){
        $id = $feature['id'];
        // Specific template for the layer has been configured
        if($templateConfigured){

          $popupFeatureContent = $popupTemplate;

          // then replace all column data by appropriate content
          foreach($feature->Attribute as $attribute){
            // Replace #col and $col by colomn name and value
            $popupFeatureContent = $popupClass->getHtmlFeatureAttribute(
              $attribute['name'],
              $attribute['value'],
              $this->repository->getKey(),
              $this->project->getKey(),
              $popupFeatureContent
            );
          }
        }
       // Use default template if needed or maptip value if defined
        else{
          $isMaptip = false;
          $maptipValue = '';

          foreach($feature->Attribute as $attribute){
            if($attribute['name'] == 'maptip'){
              $isMaptip = true;
              $maptipValue = $attribute['value'];
            }
          }
          // If there is a maptip attribute we display its value
          if($isMaptip){
            // first replace all "media/bla/bla/llkjk.ext" by full url
            $maptipValue = preg_replace_callback(
              '#(["\']){1}(media/.+\.\w{3,10})(["\']){1}#',
              Array($this, 'replaceMediaPathByMediaUrl'),
              $maptipValue
            );
            // Replace : html encoded chars to let further regexp_replace find attributes
            $maptipValue = str_replace(array('%24', '%7B', '%7D'), array('$', '{', '}'), $maptipValue);
            $popupFeatureContent = $maptipValue;
          }
          // Use default template
          else{
            $tpl = new jTpl();
            $tpl->assign('attributes', $feature->Attribute);
            $tpl->assign('repository', $this->repository->getKey());
            $tpl->assign('project', $this->project->getKey());
            $popupFeatureContent = $tpl->fetch('view~popupDefaultContent');
          }
        }

        $tpl = new jTpl();
        $tpl->assign('layerTitle', $layerTitle);
        $tpl->assign('popupContent', $popupFeatureContent);
        $content[] = $tpl->fetch('view~popup');

      } // loop features

    } // loop layers

    $content = array_reverse($content);
    return implode( "\n", $content);
  }



  /**
  * GetPrint
  * @param string $repository Lizmap Repository
  * @param string $project Name of the project : mandatory
  * @return Image rendered by the Map Server.
  */
  function GetPrint(){

    /*
    foreach($this->params as $key=>$val){
      print $key. "=>". $val."\n";
    }
     */

    // Get parameters
    if(!$this->getServiceParameters())
      return $this->serviceException();

    $url = $this->services->wmsServerURL.'?';
    /*
    $bparams = http_build_query($this->params);
    // replace some chars (not needed in php 5.4, use the 4th parameter of http_build_query)
    $a = array('+', '_', '.', '-');
    $b = array('%20', '%5F', '%2E', '%2D');
    $bparams = str_replace($a, $b, $bparams);
    $querystring = $url . $bparams;
    */

    // Filter the parameters of the request
    // for querying GetPrint
    $data = array();
    $paramsBlacklist = array('module', 'action', 'C', 'repository','project');
    foreach($this->params as $key=>$val){
      if(!in_array($key, $paramsBlacklist)){
        $data[] = strtolower($key).'='.urlencode($val);
      }
    }
    $querystring = $url . implode('&', $data);

    // Get remote data from cache
    /*
    $getRemoteData = $this->lizmapCache->getRemoteData(
      $querystring,
      $this->services->proxyMethod,
      $this->services->debugMode
    );
    $data = $getRemoteData[0];
    $mime = $getRemoteData[1];
     */
    // Get data form server
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_URL, $querystring);
    curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $data = curl_exec($ch);
    $info = curl_getinfo($ch);
    $mime = $info['content_type'];
    curl_close($ch);

    $rep = $this->getResponse('binary');
    $rep->mimeType = $mime;
    $rep->content = $data;
    $rep->doDownload  =  false;
    $rep->outputFileName  =  'getPrint';

   // Log
   $logContent ='
     <a href="'.jUrl::get('lizmap~service:index',jApp::coord()->request->params).'" target="_blank">'.$this->params['template'].'<a>
     ';
   $eventParams = array(
    'key' => 'print',
    'content' => $logContent,
    'repository' => $this->repository->getKey(),
    'project' => $this->project->getKey()
   );
   jEvent::notify('LizLogItem', $eventParams);

    return $rep;
  }

  /**
  * GetStyles
  * @param string $repository Lizmap Repository
  * @param string $project Name of the project : mandatory
  * @return SLD Style XML
  */
  function GetStyles(){

    // Get parameters
    if(!$this->getServiceParameters())
      return $this->serviceException();

    // Construction of the request url : base url + parameters
    $url = $this->services->wmsServerURL.'?';
    $bparams = http_build_query($this->params);
    $querystring = $url . $bparams;

    // Get remote data
    $getRemoteData = $this->lizmapCache->getRemoteData(
      $querystring,
      $this->services->proxyMethod,
      $this->services->debugMode
    );
    $data = $getRemoteData[0];
    $mime = $getRemoteData[1];

    // Return response
    $rep = $this->getResponse('binary');
    $rep->mimeType = 'text/xml';
    $rep->content = $data;
    $rep->doDownload  =  false;
    $rep->outputFileName  =  'qgis_style';

    return $rep;
  }


  /**
  * Send the JSON configuration file for a specified project
  * @param string $repository Lizmap Repository
  * @param string $project Name of the project
  * @return JSON configuration file for the specified project.
  */
  function getProjectConfig(){

    // Get parameters
    if(!$this->getServiceParameters())
      return $this->serviceException();

    $rep = $this->getResponse('text');
    $rep->content = $this->project->getUpdatedConfig();
    return $rep;

  }

  /**
  * PostRequest
  * @param string $xml_post
  * @return request.
  */
  protected function PostRequest( $xml_post ){
    // Get parameters
    if(!$this->getServiceParameters())
      return $this->serviceException();

    $url = $this->services->wmsServerURL.'?';

    // Filter the parameters of the request
    $data = array();
    $paramsBlacklist = array('module', 'action', 'C', 'repository','project');
    foreach($this->params as $key=>$val){
      if(!in_array($key, $paramsBlacklist)){
        $data[] = strtolower($key).'='.urlencode($val);
      }
    }
    $querystring = $url . implode('&', $data);

    // Get data form server
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_URL, $querystring);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_post);
    $data = curl_exec($ch);
    $info = curl_getinfo($ch);
    $mime = $info['content_type'];
    curl_close($ch);

    $rep = $this->getResponse('binary');
    $rep->mimeType = $mime;
    $rep->content = $data;
    $rep->doDownload = false;
    $rep->outputFileName = 'post_request';
    return $rep;
  }

  /**
  * GetFeature
  * @param string $repository Lizmap Repository
  * @param string $project Name of the project : mandatory
  * @return Image rendered by the Map Server.
  */
  function GetFeature(){

    // Get parameters
    if(!$this->getServiceParameters())
      return $this->serviceException();

    // add outputformat if not provided
    $output = $this->iParam('outputformat');
    if(!$output)
      $this->params['outputformat'] = 'GML2';

    // Construction of the request url : base url + parameters
    $url = $this->services->wmsServerURL.'?';
    $bparams = http_build_query($this->params);
    $querystring = $url . $bparams;

    // Get remote data
    $getRemoteData = $this->lizmapCache->getRemoteData(
      $querystring,
      $this->services->proxyMethod,
      $this->services->debugMode
    );
    $data = $getRemoteData[0];
    $mime = $getRemoteData[1];

    // Return response
    $rep = $this->getResponse('binary');
    /*
    if(preg_match('#^GML#', $this->params['outputformat']))
      $rep->mimeType = 'text/xml';
    else
      $rep->mimeType = 'text/json';
      * */
    $rep->mimeType = $mime;
    if (   preg_match('#^text/plain#', $mime) && strtolower( $this->params['outputformat'] ) == 'geojson' ) {
        $rep->mimeType = 'text/json';
    /*
        $layer = $this->project->findLayerByName( $this->params['typename'] );
        if ( $layer != null ) {
            $layer = $this->project->getLayer( $layer->id );
            $aliases = $layer->getAliasFields();
            $layer = json_decode( $data );
            $layer->aliases = (object) $aliases;
            $data = json_encode( $layer );
        }*/
    }
    $rep->content = $data;
    $rep->doDownload  =  false;
    $rep->outputFileName  =  'qgis_server_wfs';

    return $rep;
  }

  /**
  * DescribeFeatureType
  * @param string $repository Lizmap Repository
  * @param string $project Name of the project : mandatory
  * @return Image rendered by the Map Server.
  */
  function DescribeFeatureType(){

    // Get parameters
    if(!$this->getServiceParameters())
      return $this->serviceException();
    
    // Extensions to get aliases
    if ( strtolower( $this->params['outputformat'] ) == 'json' ) {
        $data = array();
        $layer = $this->project->findLayerByName( $this->params['typename'] );
        if ( $layer != null ) {
            $layer = $this->project->getLayer( $layer->id );
            $aliases = $layer->getAliasFields();
            $data['aliases'] = (object) $aliases;
        }
        $data = json_encode( (object) $data );
        
        // Return response
        $rep = $this->getResponse('binary');
        $rep->mimeType = 'text/json';
        $rep->content = $data;
        $rep->doDownload  =  false;
        $rep->outputFileName  =  'qgis_server_wfs';

        return $rep;
    }

    // Construction of the request url : base url + parameters
    $url = $this->services->wmsServerURL.'?';
    $bparams = http_build_query($this->params);
    $querystring = $url . $bparams;

    // Get remote data
    $getRemoteData = $this->lizmapCache->getRemoteData(
      $querystring,
      $this->services->proxyMethod,
      $this->services->debugMode
    );
    $data = $getRemoteData[0];
    $mime = $getRemoteData[1];

    // Return response
    $rep = $this->getResponse('binary');
    $rep->mimeType = $mime;
    $rep->content = $data;
    $rep->doDownload  =  false;
    $rep->outputFileName  =  'qgis_server_wfs';

    return $rep;
  }




  /**
  * GetProj4
  * @param string $repository Lizmap Repository
  * @param string $project Name of the project : mandatory
  * @param string $authid SRS or CRS authid like USER:*
  * @return Image rendered by the Map Server.
  */
  function GetProj4(){

    // Get parameters
    if(!$this->getServiceParameters())
      return $this->serviceException();

    // Return response
    $rep = $this->getResponse('text');
    $content = $this->project->getProj4( $this->iParam('authid') );
    $content = (string)$content[0];
    $rep->content = $content;
    $rep->setExpires("+300 seconds");
    return $rep;
  }

}
