<?php
define('TILDA_PROJECT_ID', '???');
define('TILDA_PUBLIC_KEY', '???');
define('TILDA_SECRET_KEY', '???');

include ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "classes" . DIRECTORY_SEPARATOR . "Tilda" . DIRECTORY_SEPARATOR . "Api.php";
include ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "classes" . DIRECTORY_SEPARATOR . "Tilda" . DIRECTORY_SEPARATOR . "LocalProject.php";

set_time_limit(0);

use \Tilda;

$api = new Tilda\Api(TILDA_PUBLIC_KEY, TILDA_SECRET_KEY);

$projectId = TILDA_PROJECT_ID;
$projectTitle = null;
if(isset($_GET['id']) && intval($_GET['id'])){
    $projectId = intval($_GET['id']);
}

$arProjects = $api->getProjectsList();
foreach($arProjects as $ind => $project){
    if($project['id'] == $projectId){
        $projectTitle = $project['title'];
        break;
    }
}

if(!$projectTitle){
    echo 'Export project with ID ' . $projectId . ' not found!';
    die;
}

echo 'Export project with ID ' . $projectId . "<br><br>";

/* Запрашиваем список страниц проекта и сохраняем ID страниц */
$arExportPages = array();
$arPages = $api->getPagesList($projectId);
if (! $arPages) {
    die("Error in connected to API: " . $api->lastError);
}

/* собираем IDшники страниц */
foreach ($arPages as $arPage) {
    $arExportPages[] = $arPage['id'];
}
unset($arPages);

/* если все таки есть, что экспортировать */
if (sizeof($arExportPages) > 0) {
    $local = new Tilda\LocalProject(array(
            'baseDir'    => '.',
            'projectDir' => '',
            'exportDir'  => $projectId . '-' . preg_replace("/[^A-Za-z0-9]/", '', $projectTitle) . '-' . date("YmdHi")
        )
    );
    /* создаем основные директории проекта (если еще не созданы) */
    if ($local->createBaseFolders() === false) {
        die("Error for create folders<br>");
    }
    
    /*  берем данные по общим файлам проекта */
    $arProject = $api->getProjectExport($projectId);
    if (! $arProject) {
        die('Not found project [' . $api->lastError . ']');
    }
    $local->setProject($arProject);
    
    $arSearchFiles = array();
    $arReplaceFiles = array();

    echo "<pre>";

    /* копируем общие CSS файлы */
    $arFiles = $local->copyCssFiles('css');
    if (! $arFiles) {
        die('Error in copy CSS files [' . $api->lastError . ']');
    }
    print_r($arFiles);
    
    /* копируем общие JS файлы */
    $arFiles = $local->copyJsFiles('js');
    if (! $arFiles) {
        die('Error in copy JS files [' . $api->lastError . ']');
    }
    print_r($arFiles);
    
    /* копируем общие IMG файлы */
    $arFiles = $local->copyImagesFiles('img');
    if (! $arFiles) {
        die('Error in copy IMG files [' . $api->lastError . ']');
    }
    print_r($arFiles);

    $res = $local->createHTAccessFile();
    if (! $res) {
        die('Error in creating .htaccess file');
    }
    
    /* перебеираем теперь страницы и скачиваем каждую по одной */
    $countexport = 1;
    foreach ($arExportPages as $pageid) {
        try {
            echo "Export page " . $pageid . "<br>";
            
            /* запрашиваем все данные для экспорта страницы */
            $tildapage = $api->getPageFullExport($pageid);
            if (! $tildapage || empty($tildapage['html'])) {
                echo "Error: cannot get page [$pageid] or page not publish<br>";
                continue;
            }

            $tildapage['export_imgpath'] = $local->arProject['export_imgpath'];
            $tildapage['needsync'] = '0';
            
            /* так как мы копировали общие файлы в одни папки, а в HTML они указывают на другие, то произведем замену */
            $html = preg_replace($local->arSearchFiles, $local->arReplaceFiles, $tildapage['html']);
            if ($html > '') {
                $tildapage['html'] = $html;
            }
            
            /* сохраним страницу  (при сохранении также происходит копирование картинок использованных на странице) */
            $tildapage = $local->savePage($tildapage);
            echo "Save page $pageid - success<br>";

            $tildapage = $local->saveMetaPage($tildapage);
        } catch (Exception $e) {
            echo "Error [".$countexport."] tilda page dont export ".$pageid." [".$e->getMessage()."]<br>";
        }
        $countexport++;
    }

    $local->archiveSite();
    echo "Export project " . $project['title'] . " - success<br>";
}
