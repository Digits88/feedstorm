<?php
/*
 * This file is part of FeedStorm
 * Copyright (C) 2014  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once 'base/fs_model.php';
require_once 'model/feed_story.php';
require_once 'model/suscription.php';
require_once 'model/story.php';

class feed extends fs_model
{
   public $url;
   public $name;
   public $description;
   public $last_check_date;
   public $last_update;
   public $suscriptors;
   public $strikes;
   public $num_stories;
   public $native_lang;
   public $parody;
   public $penalize;
   public $popularity;
   
   public function __construct($f=FALSE)
   {
      parent::__construct('feeds');
      
      $this->id = NULL;
      $this->url = NULL;
      $this->name = $this->random_string(15);
      $this->description = 'Sin descripción.';
      $this->last_check_date = 0;
      $this->last_update = 0;
      $this->suscriptors = 0;
      $this->strikes = 0;
      $this->num_stories = 0;
      $this->native_lang = TRUE;
      $this->parody = FALSE;
      $this->penalize = FALSE;
      $this->popularity = 0;
      
      if($f)
      {
         $this->id = $f['_id'];
         $this->url = $f['url'];
         $this->name = $f['name'];
         $this->description = $f['description'];
         $this->last_check_date = $f['last_check_date'];
         $this->last_update = $f['last_update'];
         $this->suscriptors = $f['suscriptors'];
         $this->strikes = $f['strikes'];
         $this->num_stories = $f['num_stories'];
         $this->native_lang = $f['native_lang'];
         
         if( isset($f['parody']) )
            $this->parody = $f['parody'];
         
         if( isset($f['penalize']) )
            $this->penalize = $f['penalize'];
         
         if( isset($f['popularity']) )
            $this->popularity = $f['popularity'];
      }
   }
   
   public function install_indexes()
   {
      $this->collection->ensureIndex('url');
      $this->collection->ensureIndex('name');
   }
   
   public function url($w3c = TRUE)
   {
      if( is_null($this->id) )
         return FS_PATH.'index.php';
      else
         return FS_PATH.'explore_feed/'.$this->id;
   }
   
   public function show_url($size=60)
   {
      if( mb_strlen($this->url) < $size )
         return $this->url;
      else
         return mb_substr($this->url, 0, $size).'...';
   }
   
   public function last_check_date()
   {
      if( is_null($this->last_check_date) )
         return '-';
      else
         return Date('Y-m-d H:m', $this->last_check_date);
   }
   
   public function last_check_timesince()
   {
      if( is_null($this->last_check_date) )
         return '-';
      else
         return $this->time2timesince($this->last_check_date);
   }
   
   public function last_update()
   {
      if( is_null($this->last_update) )
         return '-';
      else
         return Date('Y-m-d H:m', $this->last_update);
   }
   
   public function last_update_timesince()
   {
      if( is_null($this->last_update) )
         return '-';
      else
         return $this->time2timesince($this->last_update);
   }
   
   public function meneame()
   {
      return ( mb_substr($this->url, 0, 23) == 'http://www.meneame.net/' OR mb_substr($this->url, 0, 24) == 'https://www.meneame.net/' );
   }
   
   public function reddit()
   {
      return ( mb_substr($this->url, 0, 22) == 'http://www.reddit.com/' );
   }
   
   public function stories()
   {
      $feed_story = new feed_story();
      $stories = array();
      foreach($feed_story->last4feed($this->id) as $fs)
      {
         if( $fs->story() )
            $stories[] = $fs->story();
      }
      return $stories;
   }
   
   public function suscriptors()
   {
      $suscription = new suscription();
      return $suscription->count4feed($this->id);
   }
   
   public function num_stories()
   {
      $feed_story = new feed_story();
      return $feed_story->count4feed($this->id);
   }
   
   public function read()
   {
      try
      {
         if( $this->reddit() )
            $this->curl_save($this->url, 'tmp/'.$this->get_id().'.xml');
         else
            $this->curl_save($this->url, 'tmp/'.$this->get_id().'.xml', TRUE, TRUE);
         
         if( file_exists('tmp/'.$this->get_id().'.xml') )
         {
            libxml_use_internal_errors(TRUE);
            $xml = simplexml_load_file('tmp/'.$this->get_id().'.xml');
            if($xml)
            {
               /// intentamos leer las noticias
               if( $xml->channel->item )
               {
                  foreach($xml->channel->item as $item)
                     $this->new_story($item);
               }
               else if( $xml->item )
               {
                  foreach($xml->item as $item)
                     $this->new_story($item);
               }
               else if( $xml->feed->entry )
               {
                  foreach($xml->feed->entry as $item)
                     $this->new_story($item);
               }
               else if( $xml->entry )
               {
                  foreach($xml->entry as $item)
                     $this->new_story($item);
               }
               else
               {
                  $this->new_error("Estructura irreconocible en el feed: ".$this->name);
                  $this->strikes++;
               }
               
               /// leemos el titulo del feed
               if( $xml->channel->title )
                  $this->name = $this->remove_bad_utf8( (string)$xml->channel->title );
               else if( $xml->title )
               {
                  foreach($xml->title as $item)
                  {
                     $this->name = $this->remove_bad_utf8( (string)$item );
                     break;
                  }
               }
               
               /// leemos la descripción
               if( $xml->channel->description )
                  $this->description = $this->remove_bad_utf8( (string)$xml->channel->description );
               else if( $xml->description )
               {
                  foreach($xml->description as $item)
                  {
                     $this->description = $this->remove_bad_utf8( (string)$item );
                     break;
                  }
               }
            }
            else
            {
               $this->new_error("Imposible leer el xml.");
               $this->strikes++;
            }
         }
         else
         {
            $this->new_error("Imposible leer el archivo: tmp/".$this->get_id().'.xml');
            $this->strikes++;
         }
      }
      catch(Exception $e)
      {
         $this->new_error("Error al leer el feed: ".$this->url.'. '.$e);
         $this->strikes++;
      }
      
      $this->last_check_date = time();
      $this->suscriptors = $this->suscriptors();
      $this->num_stories = $this->num_stories();
      $this->save();
   }
   
   private function new_story(&$item)
   {
      $this->strikes = 0;
      
      $feed_story = new feed_story();
      $feed_story->feed_id = $this->id;
      $feed_story->title = $this->remove_bad_utf8( (string)$item->title );
      
      $story = new story();
      $story->title = $this->remove_bad_utf8( (string)$item->title );
      
      /// intentamos obtener el enlace original de meneame
      $meneos = 0;
      foreach($item->children('meneame', TRUE) as $element)
      {
         if($element->getName() == 'url')
         {
            $story->link = (string)$element;
            $feed_story->link = (string)$item->link;
         }
         else if($element->getName() == 'votes')
         {
            $meneos = intval( (string)$element );
         }
      }
      
      /// ¿La noticia es de humor?
      $story->parody = $this->parody;
      if($item->category)
      {
         foreach($item->category as $catg)
         {
            if( strpos((string)$catg, 'humor') !== FALSE )
            {
               $story->parody = TRUE;
               break;
            }
         }
      }
      
      /// ¿reddit?
      if( $this->reddit() )
      {
         $links = array();
         if( preg_match_all("/<a href=\"([^\"]*)\">\[link/", (string)$item->description, $links) )
         {
            $story->link = $links[1][0];
            $feed_story->link = (string)$item->link;
         }
      }
      
      if( is_null($story->link) )
      {
         /// intentamos obtener el enlace original de feedburner
         foreach($item->children('feedburner', TRUE) as $element)
         {
            if($element->getName() == 'origLink')
            {
               $story->link = (string)$element;
               break;
            }
         }
         
         /// intentamos leer el/los links
         if( is_null($story->link) AND $item->link)
         {
            foreach($item->link as $l)
            {
               if( mb_substr((string)$l, 0, 4) == 'http' )
                  $story->link = (string)$l;
               else
               {
                  if( $l->attributes()->rel == 'alternate' AND $l->attributes()->type == 'text/html' )
                     $story->link = (string)$l->attributes()->href;
                  else if( $l->attributes()->type == 'text/html' )
                     $story->link = (string)$l->attributes()->href;
               }
            }
         }
         
         $feed_story->link = $story->link;
      }
      
      /// reemplazamos los &amp;
      $story->link = str_replace('&amp;', '&', $story->link);
      
      if( $item->pubDate )
         $story->date = min( array( strtotime( (string)$item->pubDate ), time() ) );
      else if( $item->published )
         $story->date = min( array( strtotime( (string)$item->published ), time() ) );
      
      $feed_story->date = $story->date;
      
      if($feed_story->date > $this->last_update)
         $this->last_update = $feed_story->date;
      
      if( $item->description )
         $description = (string)$item->description;
      else if( $item->content )
         $description = (string)$item->content;
      else if( $item->summary )
         $description = (string)$item->summary;
      else
      {
         $description = '';
         /// intentamos leer el espacio de nombres atom
         foreach($item->children('atom', TRUE) as $element)
         {
            if($element->getName() == 'summary')
            {
               $description = (string)$element;
               break;
            }
         }
         foreach($item->children('content', TRUE) as $element)
         {
            if($element->getName() == 'encoded')
            {
               $description = (string)$element;
               break;
            }
         }
      }
      
      if( $this->meneame() )
      {
         /// buscamos noticias relacionadas
         $urls = array();
         if( preg_match_all('#<a href="http://www.meneame.net/story/(.+)" title=#', $description, $urls) )
         {
            foreach($urls[1] as $url)
            {
               $fs0 = $feed_story->get_by_link('http://www.meneame.net/story/'.$url);
               if($fs0)
               {
                  $story3 = $fs0->story();
                  if($story3)
                     $story->related_id = $story3->get_id();
               }
            }
         }
         
         /// quitamos el latiguillo de las noticias de menéame
         $aux = '';
         for($i = 0; $i < mb_strlen($description); $i++)
         {
            if( mb_substr($description, $i, 4) == '</p>' )
               break;
            else
               $aux .= mb_substr($description, $i, 1);
         }
         $description = $aux;
      }
      else if( $this->reddit() )
      {
         $description = $story->title;
      }
      
      /// eliminamos el html
      $description = preg_replace("/<\s*style.+?<\s*\/\s*style.*?>/si", '', html_entity_decode($description, ENT_QUOTES, 'UTF-8') );
      $story->description = $this->remove_bad_utf8( strip_tags($description) );
      
      /// ¿story ya existe?
      $story2 = $story->get_by_link($story->link);
      
      /// ¿seguro que no existe?
      if(!$story2)
      {
         $story->new_name();
         $story2 = $story->get($story->name);
      }
      
      if($story2)
      {
         /// ¿la noticia ya está enlazada con esta fuente?
         $encontrada = FALSE;
         foreach($feed_story->all4story($story2->get_id()) as $fs)
         {
            if($fs->feed_id == $this->id)
            {
               $encontrada = TRUE;
               break;
            }
         }
         if( !$encontrada )
         {
            $feed_story->story_id = $story2->get_id();
            $feed_story->save();
            
            /// ¿La fuente proporciona información nativa de una noticia no nativa?
            if( !$story2->native_lang AND $this->native_lang )
            {
               $story2->native_lang = TRUE;
               $story2->title = $story->title;
               $story2->description = $story->description;
            }
            
            /// ¿La noticia está penalizada pero la fuente no?
            if($story2->penalize AND !$this->penalize)
               $story2->penalize = FALSE;
            
            /// actualizamos la noticia
            if($meneos > $story2->meneos)
               $story2->meneos = $meneos;
            $story2->random_count( !$this->meneame() );
            $story2->num_feeds++;
            $story2->save();
         }
         else if( mt_rand(0, 4) == 0 )
         {
            /// actualizamos la noticia
            if($meneos > $story2->meneos)
               $story2->meneos = $meneos;
            $story2->random_count( !$this->meneame() );
            $story2->save();
         }
      }
      else if( $story->date > time() - FS_MAX_AGE ) /// no guardamos noticias antiguas
      {
         $story->meneos = $meneos;
         $story->random_count( $meneos == 0 );
         $story->native_lang = $this->native_lang;
         $story->penalize = $this->penalize;
         $story->num_feeds = 1;
         $story->save(); /// hay que guardar para tener un ID
         
         $feed_story->story_id = $story->get_id();
         $feed_story->save();
      }
   }
   
   public function get($id)
   {
      $this->add2history(__CLASS__.'::'.__FUNCTION__);
      
      try
      {
         $data = $this->collection->findone( array('_id' => new MongoId($id)) );
         if($data)
            return new feed($data);
         else
            return FALSE;
      }
      catch(Exception $e)
      {
         $this->new_error($e);
         return FALSE;
      }
   }
   
   public function get_by_url($url)
   {
      $this->add2history(__CLASS__.'::'.__FUNCTION__);
      $data = $this->collection->findone( array('url' => $this->var2str($url) ) );
      if($data)
         return new feed($data);
      else
         return FALSE;
   }
   
   public function exists()
   {
      if( is_null($this->id) )
         return FALSE;
      else
      {
         $this->add2history(__CLASS__.'::'.__FUNCTION__);
         $data = $this->collection->findone( array('_id' => $this->id) );
         if($data)
            return TRUE;
         else
            return FALSE;
      }
   }
   
   public function test()
   {
      if( $this->suscriptors < 0 )
         $this->suscriptors = 0;
      
      if( filter_var($this->url, FILTER_VALIDATE_URL) )
      {
         return TRUE;
      }
      else
      {
         $this->new_error('URL no válida.');
         return FALSE;
      }
      
      if($this->name == '')
         $this->name = 'sin nombre';
   }
   
   public function save()
   {
      if( $this->test() )
      {
         $data = array(
             'url' => $this->url,
             'name' => $this->ucfirst( $this->true_text_break($this->name, 30) ),
             'description' => $this->true_text_break($this->description, 200),
             'last_check_date' => $this->last_check_date,
             'last_update' => $this->last_update,
             'suscriptors' => $this->suscriptors,
             'strikes' => $this->strikes,
             'num_stories' => $this->num_stories,
             'native_lang' => $this->native_lang,
             'parody' => $this->parody,
             'penalize' => $this->penalize,
             'popularity' => $this->popularity
         );
         
         if( $this->exists() )
         {
            $this->add2history(__CLASS__.'::'.__FUNCTION__.'@update');
            $filter = array('_id' => $this->id);
            $this->collection->update($filter, $data);
         }
         else
         {
            $this->add2history(__CLASS__.'::'.__FUNCTION__.'@insert');
            $this->collection->insert($data);
            $this->id = $data['_id'];
         }
         return TRUE;
      }
      else
         return FALSE;
   }
   
   public function delete()
   {
      $this->add2history(__CLASS__.'::'.__FUNCTION__);
      $this->collection->remove( array('_id' => $this->id) );
      
      $suscription = new suscription();
      $suscription->delete4feed($this->id);
      
      $feed_story = new feed_story();
      $feed_story->delete4feed($this->id);
   }
   
   public function all()
   {
      $this->add2history(__CLASS__.'::'.__FUNCTION__);
      $feeds = array();
      foreach($this->collection->find()->sort(array('name'=>1)) as $f)
         $feeds[] = new feed($f);
      return $feeds;
   }
   
   public function popular()
   {
      $this->add2history(__CLASS__.'::'.__FUNCTION__);
      $feeds = array();
      foreach($this->collection->find()->sort(array('popularity'=>-1)) as $f)
         $feeds[] = new feed($f);
      return $feeds;
   }
   
   public function random()
   {
      $feed = FALSE;
      $all_feeds = $this->all();
      if( count($all_feeds) > 1 )
      {
         $selection = mt_rand(0, count($all_feeds));
         $i = 0;
         foreach($all_feeds as $f)
         {
            if($i == $selection)
            {
               $feed = $f;
               break;
            }
            $i++;
         }
      }
      return $feed;
   }
   
   public function cron_job()
   {
      echo "\nProcesamos las fuentes...";
      foreach($this->all() as $f)
         $f->mini_cron_job();
   }
   
   public function mini_cron_job()
   {
      if($this->strikes > 72)
      {
         $this->delete();
         echo "\n * Eliminada la fuente ".$this->url.".\n";
      }
      else
      {
         echo "\n * Procesando: ".$this->url."\n ** Archivo: tmp/".$this->get_id().".xml ...\n";
         $this->read();
         
         foreach($this->get_errors() as $e)
            echo $e."\n";
         $this->clean_errors();
         
         foreach($this->get_messages() as $m)
            echo $m."\n";
         $this->clean_messages();
      }
   }
}
