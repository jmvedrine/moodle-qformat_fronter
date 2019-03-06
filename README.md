Fronter XML question import format
==================================

This is the first version of a Moodle plugin to import question made in the Fronter LMS and exported as an XML file.

Written by Jean-Michel Védrine

This Moodle question import format was tested under Moodle 3.1, 3.2, 3.3, 3.4, 3.5, 3.6.

## Installation

### Installation from the Moodle plugin directory (prefered method)

This question import format is available from https://moodle.org/plugins/view.php?plugin=qformat_fronter

Install as any other Moodle question format plugin

#### Installation Using Git

To install using git type these commands in in the top level folder of your Moodle install:
    clone git://github.com/jmvedrine/moodle-qformat_fronter.git question/format/fronter


#### Installation From Downloaded zip file

Alternatively, download the zip from https://github.com/jmvedrine/moodle-qformat_fronter

unzip it into the question/format folder, and then rename the new folder to fronter.

You must visit Site administration and install it like any other Moodle plugin.

It will then add a new choice when you import questions in the question bank.

## Support

Please report bugs and improvements ideas using this forum thread:
https://moodle.org/mod/forum/discuss.php?d=232041

This work was made possible by comments, ideas and test files provided by Moodle users on the Moodle quiz forum:
https://moodle.org/mod/forum/view.php?id=737
It also uses some of the previous work made by Richard Rode (richard.rode@univie.ac.at) to write an import format for Moodle 1.9.

Jean-Michel Védrine
