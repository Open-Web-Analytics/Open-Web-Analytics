#
#    S P Y C
#      a simple php yaml class
#
# Load this README!
# >> $readme = Spyc::YAMLLoad('README');
#
--- %YAML:1.1
title: Spyc -- a Simple PHP YAML Class
version: 0.5
authors: [chris wanstrath (chris@ozmm.org), vlad andersen (vlad.andersen@gmail.com)]
websites: [http://www.yaml.org, http://spyc.sourceforge.net]
license: [MIT License, http://www.opensource.org/licenses/mit-license.php]
copyright: "(c) 2005-2006 Chris Wanstrath, 2006-2011 Vlad Andersen"
tested on: [php 5.2.x]

installation: >
  Copy spyc.php to a directory you can
  access with your YAML-ready PHP script.

  That's it!

about: >
  From www.yaml.org:

  "YAML(tm) (rhymes with 'camel') is a human-friendly, cross language,
  Unicode based data serialization language designed around the common
  native data structures of agile programming languages. It is broadly
  useful for programming needs ranging from configuration files to
  Internet messaging to object persistence to data auditing. Together
  with the Unicode standard for characters, the YAML specification provides
  all the information necessary to understand YAML Version 1.1 and to
  creating programs that process YAML information.

  YAML(tm) is a balance of the following design goals:
    - YAML documents are very readable by humans.
    - YAML interacts well with scripting languages.
    - YAML uses host languages' native data structures.
    - YAML has a consistent information model.
    - YAML enables stream-based processing.
    - YAML is expressive and extensible.
    - YAML is easy to implement."

  YAML makes a lot of sense.  It's easy to use, easy to learn, and cool.
  As the lucky stiff named why once said, "YAML is a beacon of light."

  If you're new to YAML, may we suggest YAML In Five Minutes:
    - http://yaml.kwiki.org/?YamlInFiveMinutes

  If you don't have five minutes, realize that this README is a completely
  valid YAML document.  Dig in, load this or any YAML file into an array
  with Spyc and see how easy it is to translate friendly text into usable
  data.

  The purpose of Spyc is to provide a pure PHP alternative to Syck, a
  simple API for loading and dumping YAML documents, a YAML loader which
  understands a usable subset of the YAML spec, and to further spread
  the glory of YAML to the PHP masses.

  If you're at all hesitant ("usable subset of YAML?!"), navigate
  http://yaml.org/start.html.  Spyc completely understands the YAML
  document shown there, a document which has features way beyond the
  scope of what normal config files might require.  Try it for yourself,
  and then start enjoying the peace of mind YAML brings to your life.

meat and a few potatoes:
  - concept: Loading a YAML document into PHP
    brief: >
      $yaml will become an array of all the data in wicked.yaml
    code: |

      include('spyc.php');

      $yaml = Spyc::YAMLLoad('wicked.yaml');

  - concept: Loading a YAML string into PHP
    brief: >
      $array will look like this:
        array('A YAML','document in a','string')
    code: |

      include('spyc.php');

      $yaml  = '- A YAML\n- document in a\n- string.';
      $array = Spyc::YAMLLoad($yaml);

  - concept: Dumping a PHP array to YAML
    brief: >
      $yaml will become a string of a YAML document created from
      $array.
    code: |

      include('spyc.php');

      $array['name']  = 'chris';
      $array['sport'] = 'curbing';

      $yaml = Spyc::YAMLDump($array);

prior art:
  - who: [Brian Ingerson, Clark Evans, Oren Ben-Kiki]
    why?: >
      The YAML spec is really a piece of work, and these guys
      did a great job on it.  A simple and elegant language like
      YAML was a long time coming and it's refreshing to know
      such able minded individuals took the task to heart and
      executed it with cunning and strength.  In addition to
      their various noteworthy contributions to YAML parsers
      and related projects, YAML.pm's README is a treasure trove
      of information for knowledge seekers.  Thanks, guys.

  - who: why the lucky stiff
    why?: >
      As the author of Syck, the code used in Ruby for the language's
      YAML class and methods, why is indirectly (directly?) responsible
      for my first exposure to YAML (as a config file in a Ruby web-app)
      and the countless hours I spent playing with this sheik new data
      format afterwards.  Syck's README is a YAML file and thus the
      inspiration for this file and, even, this very piece of software.

  - who: Steve Howell
    why?: >
      Python's YAML implementation.  PyYAML's README file is also YAML,
      so it too inspired the YAML format of this README file.

  - who: [Rasmus Lerdorf, Zeev Suraski, Andi Gutmans, et al]
    why?: >
      PHP is great at what it does best.  It's also paid a lot of my bills.
      Thanks.

bugs:
  report: >
    Please see Spyc's Sourceforge project page for information on reporting bugs.
  speed: >
    This implementation was not designed for speed.  Rather, it
    was designed for those who need a pure PHP implementation of
    a YAML parser and who are not overly concerned with performance.
    If you want speed, check out Syck.
  depth: >
    This parser is by no means a comprehensive YAML parser.  For supported
    features and future plans, check the website.
  unicode: >
    YAML is supposed to be unicode, but for now we're just using ASCII.
    PHP has crappy unicode support but who knows what the future holds.

resources:
  - http://www.yaml.org
  - http://www.yaml.org/spec/
  - http://yaml.kwiki.org/?YamlInFiveMinutes
  - http://www.whytheluckystiff.net/syck/
  - http://yaml4r.sourceforge.net/cookbook/

thanks:
  - Adam Wood
  - Daniel Ferreira
  - Aaron Jensen
  - Mike Thornton
  - Fabien Potencier
  - Mustafa Kumas