PHP Quick Profiler README
http://particletree.com/features/php-quick-profiler/

#### On This Page ####

1. Introduction and Overview of Files
2. Getting the Example Working
3. Setting up the Database Class
4. Using Smarty instead of PHP echos

#####################################
1. Introduction and Overview of Files
#####################################

PHP Quick Profiler is a helper class that outputs debugging related information
to the screen when the page has finished executing. This zip package contains a 
functional example project that utilizes the helper classes.

- index.php : The landing page of the example. Navigate to it in your browser to see the demo.
- display.php : Contains the markup for PQP.
- pqp.tpl : A Smarty variation of the PQP markup.
- /css/ : The stylesheets used by PQP.
- /images/ : The images used by PQP.
- /classes/Console.php : The class used to log items to the PQP display.
- /classes/MySqlDatabase : A sample database wrapper to explain how database logging could be implemented.
- /classes/PhpQuickProfiler : The core class that compiles the data before outputting to the browser.

##############################
2. Getting the Example Working
##############################

For the most part, the example will work once you drop it in your root directory. 
There are a few settings to check though.

- In PHPQuickProfiler.php, set the $config member variable to the path relative to your root (located in the constructor).
- If PQP does not appear after navigating to index.php in your browser, locate the destructor 
of the PQPExample class (at the bottom). Rename the function from __destruct() to display(). Then, 
manually call the function display() just underneath the class after the call to init(). The reason this would
happen is because the destructor is not firing on your server configuration.
- At this point, everything should work except for the database tab.

################################
3. Setting up the Database Class
################################

NOTE - This step does require knowledge on PHP / Database interactions. There is no copy/paste solution.

Logging database data is by far the hardest part of integrating PQP into your own project. It
requires that you have some sort of database wrapper around your code. If you do, it should be easy to implement.
To show you how it works, follow these steps with the sample database class we have provided.

- Create a database named 'test' and run the following query on it.

CREATE TABLE `Posts` (
  `PostId` int(11) unsigned NOT NULL default '0',
  PRIMARY KEY  (`PostId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1

- In index.php, uncomment out the second include, which includes the database class.
- In index.php, uncomment out the function sampleDatabaseData().
- In the sampleDatabaseData(), supply your database host, username, password, and database name.

Given those steps, database logging will be enabled. If you would like to transition this to your own database class,
open /classes/MySqlDatabase.php and note the following:

- $queryCount and $queries member variables declared on initialization
- When a query is run, the following is executed:

$start = $this->getTime();
$rs = mysql_query($sql, $this->conn);
$this->queryCount += 1;
$this->logQuery($sql, $start);

- Everything in /classes/MySqlDatabase.php under the section comment "Debugging"
must be available for the above snippet to work.

####################################
4. Using Smarty instead of PHP echos
####################################

We love Smarty and hate echos, but to make this work for everyone we set the default as echos. To show love
to the Smarty users out there, we have included a pqp.tpl file for PQP. To make it work, you would have to change
the following in /classes/PhpQuickProfiler.php:

- Add a require_once to your Smarty Library.
- In the constructor, declare an instance of Smarty: $this->smarty = new Smarty(...);
- Everywhere in in the code you see $this->output[... change it to a smarty assign. For example:

$this->output['logs'] = $logs;

... becomes ...

$this->smarty->assign('logs', $logs);

After doing it once, you'll see the pattern and can probably use a find/replace to do the rest quickly.

- Locate the display() function at the bottom. Remove the last 2 lines, and add:

$this->smarty->display('pathToDisplay.tpl');

All set after that!
