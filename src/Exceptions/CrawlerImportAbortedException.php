<?php

namespace Nqt\CrawlerOtruyen\Exceptions;

use RuntimeException;

/**
 * Người dùng hủy cào (client ngắt kết nối) — dừng vòng lặp import sớm.
 */
class CrawlerImportAbortedException extends RuntimeException {}
