<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\debug\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\debug\models\search\Debug;
use yii\web\Response;

/**
 * Debugger controller
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class DefaultController extends Controller
{
    /**
     * @inheritdoc
     */
    public $layout = 'main';
    /**
     * @var \yii\debug\Module
     */
    public $module;
    /**
     * @var array the summary data (e.g. URL, time)
     */
    public $summary;


    /**
     * @inheritdoc
     */
    public function actions()
    {
        $actions = [];
        foreach ($this->module->panels as $panel) {
            $actions = array_merge($actions, $panel->actions);
        }

        return $actions;
    }

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_HTML;
        return parent::beforeAction($action);
    }

    public function actionIndex()
    {
        $searchModel = new Debug();
        $dataProvider = $searchModel->search($_GET, $this->getManifest());

        // load latest request
        $tags = array_keys($this->getManifest());
        $tag = reset($tags);
        $this->loadData($tag);

        return $this->render('index', [
            'panels' => $this->module->panels,
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
            'manifest' => $this->getManifest(),
        ]);
    }

    public function actionView($tag = null, $panel = null)
    {
        if ($tag === null) {
            $tags = array_keys($this->getManifest());
            $tag = reset($tags);
        }
        $this->loadData($tag);
        if (isset($this->module->panels[$panel])) {
            $activePanel = $this->module->panels[$panel];
        } else {
            $activePanel = $this->module->panels[$this->module->defaultPanel];
        }

        return $this->render('view', [
            'tag' => $tag,
            'summary' => $this->summary,
            'manifest' => $this->getManifest(),
            'panels' => $this->module->panels,
            'activePanel' => $activePanel,
        ]);
    }
    
    public function actionReRun($tag)
    {
        $this->loadData($tag);
        
        $requestPanel = $this->module->panels['request'];
        
        $headers = [];
        foreach ($requestPanel->data['requestHeaders'] as $name => $value) {
            if ($name !== 'content-length') {
                $headers[] = "{$name}: {$value}";
            }
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->summary['url']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->summary['method']);
        
        if ($requestPanel->data['requestBody']['Raw']) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestPanel->data['requestBody']['Raw']);
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        $response = curl_exec($ch);
        
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        preg_match('/X-Debug-Tag: ([A-Za-z0-9]+)\b/si', $header, $debugTag);
        
        curl_close($ch);
        
        return $this->redirect(['view', 'tag' => $debugTag[1]]);
    }

    public function actionToolbar($tag)
    {
        $this->loadData($tag, 5);

        return $this->renderPartial('toolbar', [
            'tag' => $tag,
            'panels' => $this->module->panels,
            'position' => 'bottom',
        ]);
    }

    public function actionDownloadMail($file)
    {
        $filePath = Yii::getAlias($this->module->panels['mail']->mailPath) . '/' . basename($file);

        if ((mb_strpos($file, '\\') !== false || mb_strpos($file, '/') !== false) || !is_file($filePath)) {
            throw new NotFoundHttpException('Mail file not found');
        }

        return Yii::$app->response->sendFile($filePath);
    }

    private $_manifest;

    protected function getManifest($forceReload = false)
    {
        if ($this->_manifest === null || $forceReload) {
            if ($forceReload) {
                clearstatcache();
            }
            $indexFile = $this->module->dataPath . '/index.data';

            $content = '';
            $fp = @fopen($indexFile, 'r');
            if ($fp !== false) {
                @flock($fp, LOCK_SH);
                $content = fread($fp, filesize($indexFile));
                @flock($fp, LOCK_UN);
                fclose($fp);
            }

            if ($content !== '') {
                $this->_manifest = array_reverse(unserialize($content), true);
            } else {
                $this->_manifest = [];
            }
        }

        return $this->_manifest;
    }

    public function loadData($tag, $maxRetry = 0)
    {
        // retry loading debug data because the debug data is logged in shutdown function
        // which may be delayed in some environment if xdebug is enabled.
        // See: https://github.com/yiisoft/yii2/issues/1504
        for ($retry = 0; $retry <= $maxRetry; ++$retry) {
            $manifest = $this->getManifest($retry > 0);
            if (isset($manifest[$tag])) {
                $dataFile = $this->module->dataPath . "/$tag.data";
                $data = unserialize(file_get_contents($dataFile));
                foreach ($this->module->panels as $id => $panel) {
                    if (isset($data[$id])) {
                        $panel->tag = $tag;
                        $panel->load($data[$id]);
                    }
                }
                $this->summary = $data['summary'];

                return;
            }
            sleep(1);
        }

        throw new NotFoundHttpException("Unable to find debug data tagged with '$tag'.");
    }
}
