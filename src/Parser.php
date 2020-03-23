<?php


namespace TradeSafe\Mt942;


class Parser
{
    public $document;

    /**
     * Parser constructor.
     * @param string $document
     */
    public function __construct($document)
    {
        $this->document = $document;
    }

    /**
     * @return array
     */
    public function process_statement()
    {
        // Split message blocks 1, 2 & 4
        preg_match_all('/{1:(?<block1>[0-9A-Z]+)}{2:(?<block2>[0-9A-Z]+)}{4:(?<body>.*)-}/s', $this->document, $matches, PREG_SET_ORDER);

        $body = trim($matches[0]['body']);
        $lines = explode(PHP_EOL, $body);
        $lines = array_map('trim', $lines);

        $statement = [];

        // Merge multi line values onto a single line
        foreach ($lines as $key => $line) {
            if (substr($line, 0, 1) !== ':') {
                $statement[$key - 1] .= "\n" . $line;
                unset($lines[$key]);
            } else {
                $statement[$key] = $line;
            }
        }

        // Process each line
        $output = [];
        foreach ($statement as $key => $line) {
            $row = $this->process_line($line);

            $output = array_merge_recursive($output, $row);
        }

        // Merge information lines into statement data
        foreach ($output['information_lines'] as $key => $value) {
            $output['lines'][$key]['information'][] = $value;
        }

        unset($output['information_lines']);

        return [
            'block1' => $matches[0]['block1'],
            'block2' => $matches[0]['block2'],
            'block4' => $output
        ];
    }

    /**
     * @param $string string Line
     * @return array
     */
    public function process_line($string)
    {
        preg_match_all('/:(?<code>[0-9A-Z]*):(?<message>.*)/s', $string, $matches, PREG_SET_ORDER);

        return $this->format_message($matches[0]['code'], $matches[0]['message']);
    }

    /**
     * @param $code string SWIFT Tag
     * @param $message string Message
     * @return array
     */
    public function format_message($code, $message)
    {
        static $statement = 0; // Statement count; used to link :86: messages to :61: Statement Lines
        $key = '';
        $parsed_message = $message;

        /**
         * Swift MT Character Codes
         *
         * x = Alphanumeric
         * n = Number
         * d = Decimal
         * [] = Optional
         * ! = Required
         */

        switch ($code) {
            // Transaction Reference Number | 16x
            case '20':
                $key = 'reference';

                preg_match('/(?<reference>[0-9a-zA-Z]{0,16})/s', $message, $matches, PREG_UNMATCHED_AS_NULL);
                $parsed_message = $matches['reference'];
                break;
            // Related Reference | 16x
            case '21':
                $key = 'related_reference';

                preg_match('/(?<reference>[0-9a-zA-Z]{0,16})/s', $message, $matches, PREG_UNMATCHED_AS_NULL);
                $parsed_message = $matches['reference'];
                break;
            // Account Number | 35x
            case '25':
                $key = 'account_number';

                preg_match('/(?<account_number>[0-9a-zA-Z]{0,35})/s', $message, $matches, PREG_UNMATCHED_AS_NULL);
                $parsed_message = $matches['account_number'];
                break;
            // Statement Sequence / Sequence Number | 5n[/5n]
            case '28C':
                $key = 'statement_sequence';

                preg_match('/(?<statement>[0-9]{0,5})\/?(?<sequence>[0-9]{1,5})?/s', $message, $matches, PREG_UNMATCHED_AS_NULL);
                $parsed_message = [
                    'statement_number' => $matches['statement'],
                    'sequence_number' => $matches['sequence'],
                ];
                break;
            // Debit Floor Limit Indicator | 3!a[1!a]15d
            case '34F':
                preg_match('/(?<currency>[A-Z]{3})(?<type>[A-Z])?(?<amount>[0-9]+(\,[0-9][0-9]?)?)/s', $message, $matches, PREG_UNMATCHED_AS_NULL);
                $parsed_message = [
                    'currency' => $matches['currency'],
                    'type' => $matches['type'],
                    'amount' => str_replace(',', '.', $matches['amount'])
                ];

                $key = $matches[2] === 'C' ? 'credit_floor' : 'debit_floor';
                break;
            // Date/Time Indicator | 6!n4!n1!x4!n
            case '13D':
                $key = 'date_time';

                preg_match('/(?<year>[0-9]{2})(?<month>[0-9]{2})(?<day>[0-9]{2})(?<hours>[0-9]{2})(?<minutes>[0-9]{2})\+(?<offset>[0-9]{4})/s', $message, $matches, PREG_UNMATCHED_AS_NULL);

                $timestamp = mktime($matches['hours'], $matches['minutes'], 0, $matches['month'] , $matches['day'], $matches['year']);

                $parsed_message = [
                    'timestamp' => $timestamp,
                    'date' => date('c', $timestamp)
                ];
                break;
            // Statement (Transaction) Line |
            // 6!n[4!n]2a[1!a]15d1!a3!c16x[//16x]
            // [34x]
            case '61':
                $statement++; // Increment count before processing line. :86: Messages are out of sync otherwise
                $key = 'lines';

                preg_match('/(?<value_year>[0-9]{2})(?<value_month>[0-9]{2})(?<value_day>[0-9]{2})(?<entry_month>[0-9]{0,2})(?<entry_day>[0-9]{0,2})(?<indicator>[A-Z]{1})(?<funds_code>[A-Z]{1})?(?<amount>[0-9,]{1,15})(?<code>[A-Z0-9]{4})(?<customer_ref>[a-zA-Z0-9 ]{1,16})\/\/(?<institution_ref>[A-Z]{0,16})\n?(?<details>[0-9A-Z ]{0,34})?/s', $message, $matches, PREG_UNMATCHED_AS_NULL);

                $parsed_message = [];
                $parsed_message[$statement] = [
                    'year' => $matches['value_year'],
                    'month' => $matches['value_month'],
                    'day' => $matches['value_day'],
                    'entry_month' => $matches['entry_month'],
                    'entry_day' => $matches['entry_day'],
                    'indicator' => $matches['indicator'],
                    'amount' => str_replace(',', '.', $matches['amount']),
                    'code' => $matches['code'],
                    'customer_ref' => $matches['customer_ref'],
                    'institution_ref' => $matches['institution_ref'],
                    'details' => $matches['details'],
                ];
                break;
            // Information to Account Owner | 6*65x
            case '86':
                // Uses statement count set above to link an information line to a statement line
                $key = 'information_lines';

                preg_match('/(?<information>[0-9A-Z\/ ]{0,65})/s', $message, $matches, PREG_UNMATCHED_AS_NULL);

                $parsed_message = [];
                $parsed_message[$statement] = $matches['information'];
                break;
            // Number and Sum of the Debit Entries | 5n3!a15d
            case '90D':
                $key = 'debits';

                preg_match('/(?<entries>[0-9]{0,5})(?<currency>[A-Z]{3})(?<amount>[0-9,]{0,15})/s', $message, $matches, PREG_UNMATCHED_AS_NULL);

                $parsed_message = [
                    'entries' => $matches['entries'],
                    'currency' => $matches['currency'],
                    'amount' => str_replace(',', '.', $matches['amount']),
                ];
                break;
            // Number and Sum of the Credit Entries | 5n3!a15d
            case '90C':
                $key = 'credits';

                preg_match('/(?<entries>[0-9]{0,5})(?<currency>[A-Z]{3})(?<amount>[0-9,]{0,15})/s', $message, $matches, PREG_UNMATCHED_AS_NULL);

                $parsed_message = [
                    'entries' => $matches['entries'],
                    'currency' => $matches['currency'],
                    'amount' => str_replace(',', '.', $matches['amount']),
                ];
                break;
        }

        return [
            $key => $parsed_message
        ];
    }
}