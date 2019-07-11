<?php

namespace Masala;

use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\Regex;
use MongoDB\Client;
use MongoDB\Cursor;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\UI\Control;
use Nette\Application\IPresenter;
use Nette\InvalidStateException;
use Nette\Localization\ITranslator;
use PHPExcel;
use PHPExcel_IOFactory;
use PHPExcel_Style_Alignment;
use PHPExcel_Writer_Excel2007;

/** @author Lubomir Andrisek */
class MongoBuilder extends Control implements IBuilderFactory {

    /** @var array */
    protected $arguments = [];

    /** @var Client */
    protected $client;

    /** @var string */
    protected $collection = '';

    /** @var array */
    private $columns = [];

    /** @var string */
    protected $database;

    /** @var string */
    protected const DATE = 'Y-m-d';

    /** @var string */
    protected $folder = '';

    /** @var int */
    private $group;

    /** @var array */
    private $groups = [];

    /** @var array */
    protected $options = ['limit' => 20];

    /** @var string */
    protected $path  = '';

    /** @var array */
    private $props = [];

    /** @var array */
    protected $state;

    /** @var ITranslator */
    protected $translatorRepository;

    /** @var array */
    private $where = [];

    public function __construct(string $database, Client $client, ITranslator $translatorRepository) {
        $this->client = $client;
        $this->database = $database;
        $this->translatorRepository = $translatorRepository;
    }

    public function clone(): IBuilderFactory {
        return clone $this;
    }

    protected function fetch(): IBuilderFactory {
        return $this;
    }

    private function format(array $data): array {
        $formats = [];
        foreach($data as $key => $value) {
            if(is_numeric($value) && preg_match('/\./', $value)) {
                $formats[$key] = floatval($value);
            } else if(is_numeric($value)) {
                $formats[$key] = intval($value);
            } else {
                $formats[$key] = $value;
            }
        }
        return $formats;
    }

    public function handleExport(): void {
        $this->state()->state->Rows = (object) $this->client->selectCollection($this->database, $this->collection)->find($this->arguments, $this->options)->toArray();
        if(1 == $this->state->Paginator->Current) {
            $excel = new PHPExcel();
            $title = 'export';
            $properties = $excel->getProperties();
            $properties->setTitle($title);
            $properties->setSubject($title);
            $properties->setDescription($title);
            $excel->setActiveSheetIndex(0);
            $sheet = $excel->getActiveSheet();
            $sheet->setTitle(substr($title, 0, 31));
            $letter = 'a';
            foreach($this->row(reset($this->state->Rows)) as $column => $value) {
                $sheet->setCellValue($letter . '1', $column);
                $sheet->getColumnDimension($letter)->setAutoSize(true);
                $sheet->getStyle($letter . '1')->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
                $letter++;
            }
            $writer = new PHPExcel_Writer_Excel2007($excel);
            $this->state->file = $this->file();
            $writer->save($this->folder . $this->state->file);
            unset($this->state->download);
        } else {
            $excel = PHPExcel_IOFactory::load($this->folder . $this->state->file);
            $excel->setActiveSheetIndex(0);
        }
        $last = $excel->getActiveSheet()->getHighestRow();
        foreach($this->state->Rows as $key => $row) {
            $last++;
            $letter = 'a';
            foreach($this->row($row) as $cell) {
                $excel->getActiveSheet()->SetCellValue($letter++ . $last, $cell);
            }
        }
        $writer = new PHPExcel_Writer_Excel2007($excel);
        $writer->save($this->folder . $this->state->file);
        if($this->state->Paginator->Current == $this->state->Paginator->Last) {
            $this->state->download = $this->path . $this->state->file;
        }
        $this->state->Paginator->Current++;
        $this->presenter->sendResponse(new JsonResponse($this->state));
    }

    public function handlePage(): void {
        if(empty($this->state()->state->Where)) {
            /** https://docs.mongodb.com/manual/reference/method/db.collection.totalSize/ */
            $sum = $this->database->query('SHOW TABLE STATUS WHERE Name = "' . $this->table . '"')->fetch()->Rows;
        } else if(!empty($this->groups) && isset($this->groups[$this->group])) {
           $sum = $this->client->selectCollection($this->database, $this->collection)
                    ->aggregate(['$match' => $this->arguments], ['$group' => ['_id' => null, 'total' => ['$sum' => '$qte']]]);
           //$sum = $this->database->query(preg_replace('/SELECT(.*)FROM/', 'SELECT COUNT(*) AS sum FROM', $this->sum['query']), ...$this->sum['arguments'])->getRowCount();
        } else {
            $sum = intval($this->client->selectCollection($this->database, $this->collection)->count($this->arguments));
        }
        $this->state->Paginator->Last = ceil($sum / $this->options['limit']);
        $this->state->Paginator->Sum = $sum;
        $this->presenter->sendResponse(new JsonResponse($this->state));
    }

    public function handleState(): void {
        $this->state();
        if(!empty($this->groups) && isset($this->groups[$this->group])) {
            $this->state->Rows = $this->client->selectCollection($this->database, $this->collection)->aggregate(['$match' => $this->arguments], ['$group' => [$this->groups[$this->group] => null]]);
        } else {
            $this->state->Rows = $this->client->selectCollection($this->database, $this->collection)->find($this->arguments, $this->options)->toArray();
        }
        $this->presenter->sendResponse(new JsonResponse($this->state));
    }

    public function limit(int $limit): IBuilderFactory {
        $this->options['limit'] = $limit;
        return $this;
    }

    public function order(array $order): IBuilderFactory {
        $sort = [];
        foreach($order as $column => $value) {
            $sort[$column] = 'ASC' == $value ? 1 : -1;
        }
        $this->options['sort'] = $sort;
        return $this;
    }

    protected function prop(string $key, $value): IBuilderFactory {
        $this->props[$key] = $value;
        return $this;
    }

    public function props(): array {
        $this->props['download'] = ['label' => $this->translatorRepository->translate(1140.0)];
        $this->props['export'] = ['label' => $this->translatorRepository->translate('export'),
                                 'link' => $this->link('export')];
        $this->props['Paginator'] = ['link' => $this->link('state'),
                                'next' => $this->translatorRepository->translate(1205.0),
                                'page' => ucfirst($this->translatorRepository->translate(1205.1)),
                                'previous' => $this->translatorRepository->translate(1205.2),
                                'sum' => $this->translatorRepository->translate(1205.3)];
        $this->props['page'] = $this->link('page');
        $this->props['state'] = ['label' => $this->translatorRepository->translate(1143.0),
                                 'link' => $this->link('state')];
        foreach($this->columns as $column) {
            $this->props[$column]['link'] = $this->link('state');
        }
        return $this->props;
    }

    protected function select(string $column, string $label, string $alias = null): IBuilderFactory {
        if(preg_match('/\sAS\s/', $alias)) {
            throw new InvalidStateException('Use intented alias as key in column ' . $column . '.');
        }
        $column = empty($alias) ? $column : $alias;
        if(!isset($this->props[$column])) {
            $this->props[$column] = ['label' => $label];
        }
        $this->columns[$column] = $column;
        return $this;
    }

    protected function set(array $set): IBuilderFactory {
        $arguments = array_slice($this->arguments, 0, sizeof($this->arguments) - 2);
        $this->client->selectCollection($this->database, $this->collection)
             ->find($arguments)
             ->updateMany($set);
        return $this;
    }

    protected function state(): IBuilderFactory {
        $this->state = json_decode(file_get_contents('php://input'), false);
        foreach($this->state->Order as $alias => $sort) {
            if(isset($this->columns[$alias]) && !empty($sort)) {
                $this->options['sort'] = [$alias => 'ASC' == $sort ? 1 : -1];
            }
        }
        foreach($this->state->Where as $alias => $column) {
            if(isset($this->props[preg_replace('/(>|<|=|\s)/', '', $alias)])) {
                $this->where($alias, $column, !empty($column));
            }
        }
        foreach ($this->where as $column => $value) {
            $key = preg_replace('/\s(.*)/', '', $column);
            if(is_array($value)) {
                $this->arguments[$key] = ['$in' => $this->format($value)];
            } else if (preg_match('/\s\>\=/', $column) && (bool) strpbrk($value, 1234567890) && is_int($converted = strtotime($value)) && preg_match('/\-|.*\..*/', $value)) {
                $this->arguments[$key] = ['$gte' => new UTCDateTime($converted)];
            } elseif (preg_match('/\s\>\=/', $column)) {
                $this->arguments[$key] = ['$gte' => $value];
            } elseif (preg_match('/\s\<\=/', $column) && (bool) strpbrk($value, 1234567890) && is_int($converted = strtotime($value)) && preg_match('/\-|.*\..*\./', $value)) {
                $this->arguments[$key] = ['$lte' => new UTCDateTime($converted)];
            } elseif (preg_match('/\s\<\=/', $column)) {
                $this->arguments[$key] = ['$lte' => $value];
            } elseif (preg_match('/\s\>/', $column) && (bool) strpbrk($value, 1234567890) && is_int($converted = strtotime($value)) && preg_match('/\-|.*\..*/', $value)) {
                $this->arguments[$key] = ['$gt' => new UTCDateTime($converted)];
            } elseif (preg_match('/\s\>/', $column)) {
                $this->arguments[$key] = ['$gt' => $value];
            } elseif (preg_match('/\s\</', $column) && (bool) strpbrk($value, 1234567890) && is_int($converted = strtotime($value)) && preg_match('/\-|.*\..*/', $value)) {
                $this->arguments[$key] = ['$lt' => new UTCDateTime($converted * 1000)];
            } elseif (preg_match('/\s\</', $column)) {
                $this->arguments[$key] = ['$gt' => $value];
            } elseif (preg_match('/\s\!=/', $column)) {
                 $this->arguments[$key] = ['$not' => ['$eq' => $value]];
            } elseif ((bool) strpbrk($value, 1234567890) && is_int($converted = strtotime($value)) && preg_match('/\-|.*\..*/', $value)) {
                $this->arguments[$column] = new UTCDateTime($converted);
            } elseif (is_numeric($value) || !isset($this->state->Where->$column)) {
                $this->arguments[$column] = $value;
            } else {
                $this->arguments[$column] = new Regex('.*' . $value . '.*', 's');
            }
        }
        $this->options['skip'] = ($this->state->Paginator->Current - 1) * $this->options['limit'];
        return $this;
    }

    public function table(string $collection): IBuilderFactory {
        $this->collection = $collection;
        return $this;
    }

    public function where(string $key, $column, bool $condition = null): IBuilderFactory {
        if(is_bool($condition) && false == $condition) {
        } elseif (is_callable($condition) && false != $value = $condition()) {
            $this->where[$key] = $value;
        } elseif (is_bool($condition) && $condition && is_array($column)) {
            $this->where[$key] = $column;
        } else {
            $this->where[$key] = $column;
        }
        return $this;
    }

}
