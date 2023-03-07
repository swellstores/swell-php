<?php

namespace Swell;

/**
 * Represents a client resource containing a collection of records
 * Primarily used in paginatte record results
 */
class Collection extends Resource
{
  /**
   * @var int
   */
  public $count;

  /**
   * @var array
   */
  public $pages;

  /**
   * @var int
   */
  public $page;

  /**
   * @param  mixed $result
   * @param  Client $client
   */
  function __construct($result, $client = null)
  {
    foreach ($result['$data'] as $key => $value) {
      switch ($key) {
        case "results":
          break;
        case "count":
          $this->count = $value;
          break;
        case "pages":
          $this->pages = $value;
          break;
        case "page":
          $this->page = $value;
          break;
        default:
          $this->{$key} = $value;
      }
    }

    $result['$data'] = isset($result['$data']["results"])
      ? $result['$data']["results"]
      : null;
    $result = $this->build_records($result);

    parent::__construct($result, $client);
  }

  /**
   * Build records from result data
   *
   * @param  array $result
   * @return array
   */
  protected function build_records($result): array
  {
    $url = $result['$url'];
    $parent_url = $url;
    if (false !== ($pos = strpos($url, "?"))) {
      $url = substr(0, $pos);
    }

    $url = "/" . trim($url, "/");
    foreach ($result['$data'] as $key => $record) {
      $record_url = $url;
      if (isset($record["id"])) {
        $record_url .= "/" . $record["id"];
      }
      self::$client_links[$record_url] = &self::$client_links[$parent_url];
      $result['$data'][$key] = new Record([
        '$url' => $record_url,
        '$data' => $record,
      ]);
    }

    return $result;
  }

  /**
   * Check if collection field value exists
   *
   * @param  string $index
   * @return bool
   */
  public function offsetExists($index): bool
  {
    if (!($exists = parent::offsetExists($index))) {
      if (isset($this->{$index})) {
        return true;
      }
    }
    return $exists;
  }

  /**
   * Get collection record or meta data
   *
   * @param  string $index
   * @return mixed
   */
  #[\ReturnTypeWillChange]
  function offsetGet($index)
  {
    if (isset($this->{$index})) {
      return $this->{$index};
    } else {
      $records = $this->records();
      if ($index === "results") {
        return $records;
      }
      if ($record = &$records[$index]) {
        return $record;
      }
      foreach ((array) $records as $key => $record) {
        if ($record["id"] === $index) {
          $record = &$records[$key];
          return $record;
        }
      }
    }

    return null;
  }

  /**
   * Get raw record values
   *
   * @return mixed
   */
  #[\ReturnTypeWillChange]
  function records()
  {
    return $this->getArrayCopy();
  }

  /**
   * Get collection data
   *
   * @param  bool $raw
   * @return mixed
   */
  #[\ReturnTypeWillChange]
  public function data($raw = false)
  {
    $data = [
      "count" => $this->count,
      "results" => parent::data($raw),
      "pages" => $this->pages,
      "page" => $this->page,
    ];

    return $data;
  }

  /**
   * Dump raw collection values
   *
   * @param  bool $return
   * @param  bool $print
   * @return void
   */
  function dump($return = false, $print = true, $depth = 1)
  {
    $dump = [];
    $dump["count"] = $this->count;
    $dump["results"] = [];
    foreach ((array) $this->records() as $key => $record) {
      if ($record instanceof Resource) {
        $dump["results"][$key] = $record->data();
        foreach ($record->links as $field => $link) {
          if ($depth < 1) {
            try {
              $link_record = $record[$field];
            } catch (ServerException $e) {
              $link_record = ['$error' => $e->getMessage()];
            }

            if ($link_record instanceof Resource) {
              $dump["results"][$key][$field] = $link_record->dump(
                true,
                false,
                $depth + 1
              );
            } else {
              $dump["results"][$key][$field] = $link_record;
            }
          }
        }
      }
    }
    if ($dump["results"] && ($links = $this->links)) {
      $dump['$links'] = $this->dump_links($links);
    }
    if ($dump["count"] > 0) {
      $dump["page"] = $this->page;
    }
    if ($this->pages) {
      $dump["pages"] = $this->pages;
    }

    if ($print) {
      return print_r($dump, $return);
    } else {
      return $dump;
    }
  }
}
