<?php
/*
   SQLBrite is Copyright 2013 by Elod Csirmaz <http://www.github.com/csirmaz>

   This program is free software: you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation, either version 3 of the License, or
   (at your option) any later version.

   This program is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU General Public License for more details.

   You should have received a copy of the GNU General Public License
   along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

/*
The SQLBrite class wraps a SQLite3 object and defines some convenience
methods.

To instantiate an object, use:
  $DB = new SQLBrite(new SQLite3($FILENAME))

Then call the methods defined below to execute result-less queries and
queries that return a number of rows.

Usually, the methods accept an array of values after the query itself. If
the array is present, any question marks in the query are replaced by the
quoted and escaped values in the array. This replacement does not take place
if the array is missing or FALSE is given instead.

For example:
	$DB->exec('UPDATE mytable SET col = 1 WHERE id = ?', array(12));
	$DB->exec("UPDATE mytable SET col = 1 WHERE text = 'Where?'");

By default, an exception will be thrown on an error, but this can be changed
by subclassing SQLBrite and overriding the error() method.
*/

class SQLBrite {

   private $DB;

   public function __construct($DB) {
      $this->DB = $DB;
   }

   /* exec($sql [, $values])
      Executes a result-less query and reports any errors.
      If $values is given, it should be an array of values
      to replace placeholders in the query. See sql().
   */
   public function exec($sql, $values = false) {
      $s = ($values === false ? $sql : $this->sql($sql, $values));
      if (!$this->DB->exec($s)) {
         $this->sqlerror($s);
      }
   }

   /* exec_assert_change($sql, $values, $expected_no_rows)
      Executes a result-less query.
      Checks the number or rows affected, and reports an error if
      it is different from $expected_no_rows, the expected value.
      $values should be an array of values to replace placeholders
      in the query (see sql()), or FALSE to skip the replacement.
   */
   public function exec_assert_change($sql, $values, $expected_no_rows) {
      $s = ($values === false ? $sql : $this->sql($sql, $values));
      $this->exec($s);
      $r = $this->DB->changes();
      if ($r != $expected_no_rows) {
         $this->error('SQLite query "' . $s . '" changed "' . $r . '" rows instead of "' . $expected_no_rows . '"');
      }
   }

   /* Automatically close the DB connection
      when the object is garbage collected.
   */
   public function __destruct() {
      $this->close();
   }

   /* querysingle($sql [, $values ])
      Executes a query and returns a single result
      (the value of the first column), and reports any errors.
      If $values is given, it should be an array of values
      to replace placeholders in the query. See sql().
      Returns NULL if the query does not match any rows.
   */
   public function querysingle($sql, $values = false) {
      $s = ($values === false ? $sql : $this->sql($sql, $values));
      $r = $this->DB->querySingle($s);
      if ($r === false) {
         $this->sqlerror($s);
      }
      return $r;
   }

   /* querysingle_strict($sql [, $values ])
      Executes a query and returns a single result
      (the value of the first column), and reports any errors.
      Reports an error if the query does not match any rows.
      If $values is given, it should be an array of values
      to replace placeholders in the query. See sql().
   */
   public function querysingle_strict($sql, $values = false) {
      $s = ($values === false ? $sql : $this->sql($sql, $values));
      $r = $this->DB->querySingle($s);
      if ($r === false) {
         $this->sqlerror($s);
      }
      if (is_null($r)) {
         $this->error('SQLite query "' . $s . '" did not match any rows (querysingle_strict');
      }
      return $r;
   }

   /* querysinglerow($sql [, $values ])
      Executes a query and returns the first row.
      Reports any errors, and returns and empty array
      if there were no results.
      If $values is given, it should be an array of values
      to replace placeholders in the query. See sql().
   */
   public function querysinglerow($sql, $values = false) {
      $s = ($values === false ? $sql : $this->sql($sql, $values));
      $r = $this->DB->querySingle($s, true);
      if ($r === false) {
         $this->sqlerror($s);
      }
      return $r;
   }

   /* fetchall($sql [, $values ])
      Executes a query and returns all rows as an array of arrays.
      The internal arrays are indexed by column name.
      If $values is given, it should be an array of values
      to replace placeholders in the query. See sql().
   */
   public function fetchall($sql, $values = false) {
      $s = ($values === false ? $sql : $this->sql($sql, $values));
      $r = $this->DB->query($s);
      if ($r === false) {
         $this->sqlerror($s);
      }
      if ($r === true) {
         $this->error('SQLite query "' . $s . '" succeeded but was expected to return results.');
      }
      $rows = array();
      while (($e = $r->fetchArray(SQLITE3_ASSOC)) !== false) {
         array_push($rows, $e);
      }
      $r->finalize();
      return $rows;
   }

   /* query_callback($sql, $values, $callback)
      Executes a query and calls a callback function for each row
      with an array indexed by column name as its argument.
      $values should be an array of values to replace placeholders
      in the query (see sql()), or FALSE to skip replacement.
      Return FALSE from the callback to stop looping.
   */
   public function query_callback($sql, $values, $callback) {
      $s = ($values === false ? $sql : $this->sql($sql, $values));
      $r = $this->DB->query($s);
      if ($r === false) {
         $this->sqlerror($s);
      }
      if ($r === true) {
         $this->error('SQLite query "' . $s . '" succeeded but was expected to return results.');
      }
      while (($e = $r->fetchArray(SQLITE3_ASSOC)) !== false) {
         if ($callback($e) === false) {
            break;
         }
      }
      $r->finalize();
   }

   /* close()
      Closes the database connection.
      Returns TRUE on success, and FALSE on failure.
   */
   public function close() {
      return $this->DB->close();
   }

   /* sql($sql, $values)
      Replaces all ?s in the query with the values passed in in an array,
      escaped and wrapped in single quotes.
      For example:
         sql('SELECT * FROM mytable WHERE id = ?', array(12))

      One can consider extending SQLBrite to use prepare() and statement
      objects to replace placeholders. Although PHP's documentation of
      SQLite3Stmt::bindValue() is unclear on this point, from a comment it
      appears that it supports '?' placeholders, from which I presume that
      it supports all types of placeholders listed in
      http://www.sqlite.org/c3ref/bind_blob.html . SQLBrite methods could
      accept either an array of values, as they currently do, or an array
      with the keys specified to address named placeholders. As the indices
      of non-named placeholders start at 1, we would need to increment any
      numeric keys in this array before calling bindValue(). We would also
      need to re-implement calls to querySingle(). It is unclear how one
      would be able to specify the type of the value. Also, as bindValue()
      would need to be called for each different placeholder, I am unsure
      whether this solution would be faster.
   */
   public function sql($sql, $values) {
      $s = '';
      $i = 0;
      foreach (preg_split('/(\?)/', $sql, NULL, PREG_SPLIT_DELIM_CAPTURE) as $e) {
         if ($e == '?') {
            $s.= "'" . $this->DB->escapeString($values[$i]) . "'";
            $i++;
         } else {
            $s.= $e;
         }
      }
      return $s;
   }

   /* Reports an error */
   public function error($msg) {
      throw new Exception($msg);
   }

   /* Reports an error with a query,
      including the error message from the database.
   */
   protected function sqlerror($sql) {
      $this->error('SQLite error on query "' . $sql . '": "' . $this->DB->lastErrorMsg() . '"');
   }

}

?>