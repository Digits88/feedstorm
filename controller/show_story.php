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

require_once 'model/comment.php';
require_once 'model/story.php';
require_once 'model/story_preview.php';
require_once 'model/story_visit.php';
require_once 'model/topic.php';

class show_story extends fs_controller
{
   public $comments;
   public $feed_links;
   public $meneame_link;
   public $preview;
   public $reddit_link;
   public $story;
   public $stories;
   public $txt_comment;
   
   public function __construct()
   {
      parent::__construct('show_story', 'Artículo...');
      $this->preview = new story_preview();
      $this->stories = array();
      $story = new story();
      
      if( isset($_GET['id']) )
         $this->story = $story->get($_GET['id']);
      else
         $this->story = FALSE;
      
      if($this->story)
      {
         $this->title = $this->story->title;
         $this->comments = $this->comments();
         
         /// cargamos y analizamos las fuentes
         $this->meneame_link = FALSE;
         $this->reddit_link = FALSE;
         $this->feed_links = $this->story->feed_links();
         foreach($this->feed_links as $fl)
         {
            if( $fl->meneame() )
               $this->meneame_link = $fl->link;
            else if( $fl->reddit() )
               $this->reddit_link = $fl->link;
         }
         
         $this->eval_quality();
         
         if( !$this->story->readed() AND $this->visitor->human() AND  isset($_SERVER['REMOTE_ADDR']) )
         {
            $this->story->read();
            
            $story_visit = new story_visit();
            $sv0 = $story_visit->get_by_params($this->story->get_id(), $_SERVER['REMOTE_ADDR']);
            if( !$sv0 )
            {
               $story_visit->visitor_id = $this->visitor->get_id();
               $story_visit->story_id = $this->story->get_id();
               $story_visit->save();
               $this->story->clics++;
               $this->story->save();
            }
         }
         
         if(count($this->get_errors()) + count($this->get_messages()) == 0)
         {
            if( !$this->story->native_lang )
               $this->new_message('¿Te atreves a traducir este artículo? Haz clic en la pestaña <b>editar</b>.');
            else if(mt_rand(0, 9) == 0)
               $this->new_message('Si tienes más información o hay algún error en el artículo, no lo dudes, haz clic en la pestaña <b>editar</b>.');
         }
      }
      else
      {
         $this->new_error_msg('Artículo no encontrado. <a href="'.FS_PATH.'index.php?page=search">Usa el buscador</a>.');
         $this->stories = $story->popular_stories();
      }
   }
   
   public function url()
   {
      if($this->story)
         return $this->story->url();
      else
         return parent::url();
   }
   
   public function full_url()
   {
      if($this->story)
         return $this->domain().$this->story->url(FALSE);
      else
         return $this->domain();
   }
   
   public function get_description()
   {
      if($this->story)
         return $this->story->description();
      else
         return parent::get_description();
   }
   
   public function get_keywords()
   {
      if($this->story)
         return $this->story->keywords;
      else
         return parent::get_keywords();
   }
   
   public function twitter_url()
   {
      if($this->story)
      {
         $url = 'https://twitter.com/share?url='.urlencode( $this->full_url() ).
            '&amp;text='.urlencode( html_entity_decode($this->story->title) );
         if( isset($this->story->link) AND $this->story->num_editions == 0 )
         {
            $url = 'https://twitter.com/share?url='.urlencode($this->story->link).
               '&amp;text='.urlencode( html_entity_decode($this->story->title) );
         }
         return $url;
      }
      else
         return 'https://twitter.com/share';
   }
   
   public function facebook_url()
   {
      if($this->story)
      {
         $url = 'http://www.facebook.com/sharer.php?s=100&amp;p[title]='.urlencode( html_entity_decode($this->story->title) ).
            '&amp;p[url]='.urlencode( $this->full_url() );
         if( isset($this->story->link) AND $this->story->num_editions == 0 )
         {
            $url = 'http://www.facebook.com/sharer.php?s=100&amp;p[title]='.urlencode( html_entity_decode($this->story->title) ).
               '&amp;p[url]='.urlencode($this->story->link);
         }
         return $url;
      }
      else
         return 'http://www.facebook.com/sharer.php';
   }
   
   public function plusone_url()
   {
      if($this->story)
      {
         $url = 'https://plus.google.com/share?url='.urlencode( $this->full_url() );
         if( isset($this->story->link) AND $this->story->num_editions == 0 )
         {
            $url = 'https://plus.google.com/share?url='.urlencode($this->story->link);
         }
         return $url;
      }
      else
         return 'https://plus.google.com/share';
   }
   
   private function comments()
   {
      $comment = new comment();
      
      if( isset($_GET['delete_comment']) )
      {
         $com0 = $comment->get($_GET['delete_comment']);
         if($com0)
         {
            $com0->delete();
            $this->new_message('Comentario eliminado correctamente.');
         }
         else
            $this->new_error_msg('Comentario no encontrado.');
      }
      
      $this->txt_comment = '';
      $all_comments = $this->story->comments();
      
      if( isset($_POST['comment']) )
      {
         $this->txt_comment = trim($_POST['comment']);
         
         if($this->visitor->human() AND ($_POST['human'] == '' OR $this->visitor->admin) )
         {
            if( mb_strlen($this->txt_comment) > 1 )
            {
               $comment = new comment();
               $comment->thread = $this->story->get_id();
               $comment->visitor_id = $this->visitor->get_id();
               $comment->nick = $this->visitor->nick;
               $comment->text = $this->txt_comment;
               $comment->save();
               $all_comments[] = $comment;
               
               /// actualizamos al visitante
               $this->visitor->num_comments++;
               $this->visitor->need_save = TRUE;
               $this->visitor->save();
               
               $this->new_message('Comentario enviado correctamente.');
               $this->txt_comment = '';
            }
            else
               $this->new_error_msg('Tienes que escribir más.');
         }
         else
            $this->new_error_msg('Tienes que borrar el número para demostrar que eres humano.');
      }
      
      return $all_comments;
   }
   
   public function related_stories()
   {
      $stories = array();
      
      $story = $this->story->related_story();
      $max_stories = 5;
      while($max_stories > 0)
      {
         if($story)
         {
            $stories[] = $story;
            $story = $story->related_story();
            $max_stories--;
         }
         else
            break;
      }
      
      return $stories;
   }
   
   /// devuelve TRUE si el enlace pertenece a un medio de AEDE
   private function aede($link)
   {
      $aede_domains = array(
          'abc.es', 'aede.es', 'as.com', 'canarias7.es', 'cincodias.com', 'deia.com', 'diaridegirona.cat',
          'diaridetarragona.com', 'diarideterrassa.es', 'diariocordoba.com', 'diariodeavila.es', 'diariodeavisos.com',
          'diariodecadiz.es', 'diariodeibiza.es', 'diariodejerez.es', 'diariodelaltoaragon.es', 'diariodeleon.es',
          'diariodemallorca.es', 'diariodenavarra.es', 'diariodenoticias.org', 'diariodesevilla.es', 'diarioinformacion.com',
          'diariojaen.es', 'diariopalentino.es', 'diariovasco.com', 'diariovasco.com', 'eladelantado.com', 'elalmeria.es',
          'elcomercio.es', 'elcorreo.com', 'elcorreoweb.es', 'eldiadecordoba.es', 'eldiariomontanes.es', 'eleconomista.es',
          'elmundo.es', 'elpais.com', 'elpais.es', 'elperiodico.com', 'elperiodicodearagon.com', 'elperiodicoextremadura.com',
          'elperiodicomediterraneo.com', 'elprogreso.es', 'europasur.es', 'expansion.com', 'farodevigo.es', 'granadahoy.com',
          'heraldo.es', 'heraldodesoria.es', 'hoy.es', 'ideal.es', 'intereconomia.com/la-gaceta', 'lagacetadesalamanca.es',
          'laopinion.es', 'laopinioncoruna.es', 'laopiniondemalaga.es', 'laopiniondemurcia.es', 'laopiniondezamora.es',
          'laprovincia.es', 'larazon.es', 'larioja.com', 'lasprovincias.es', 'latribunadealbacete.es', 'latribunadeciudadreal.es',
          'latribunadetalavera.es', 'latribunadetoledo.es', 'lavanguardia.com', 'laverdad.es', 'laverdad.es', 'lavozdealmeria.es',
          'lavozdegalicia.es', 'lavozdigital.es', 'levante-emv.com', 'lne.es', 'majorcadailybulletin.es', 'malagahoy.es',
          'marca.com', 'mundodeportivo.com', 'noticiasdealava.com', 'noticiasdegipuzkoa.com', 'regio7.cat', 'sport.es',
          'superdeporte.es', 'ultimahora.es'
      );
      
      $parts = explode('/', $link);
      if( count($parts) >= 3 )
      {
         $link_domain = str_replace('www.', '', $parts[2]);
         return in_array($link_domain, $aede_domains);
      }
      else
         return FALSE;
   }
   
   /*
    * Evaluamos la calidad del artículo para decidir si lo hacemo público o no
    */
   private function eval_quality()
   {
      /// si es de AEDE no lo publicamos
      if( $this->aede($this->story->link) )
      {
         $this->new_message('Este artículo pertenece a un medio de AEDE, esa organización que pretende'
            . ' cobrar un canon cada vez que alguien ponga un enlace a otra web.');
      }
      else if($this->story->published) /// si está en portada lo publicamos, logicamente
      {
         $this->noindex = FALSE;
      }
      else if($this->story->native_lang) /// si no está en español no lo publicamos
      {
         $this->noindex = TRUE;
      }
      else if($this->story->num_editions > 0) /// si hay alguna edición lo publicamos
      {
         $this->noindex = FALSE;
      }
      else if( isset($this->story->related_id) ) /// si tiene artículos relacionados lo publicamos
      {
         $this->noindex = FALSE;
      }
      else if($this->story->num_comments > 0)
      {
         $this->noindex = FALSE;
      }
   }
   
   public function topics()
   {
      $tlist = array();
      $topic = new topic();
      $topics = array();
      
      foreach($this->story->topics as $tid)
         $topics[] = $topic->get($tid);
      
      foreach($topics as $t1)
      {
         $found = FALSE;
         foreach($topics as $t2)
         {
            if( $t2->parent == (string)$t1->get_id() )
            {
               $found = TRUE;
               break;
            }
         }
         if(!$found)
            $tlist[] = $t1;
      }
      
      return $tlist;
   }
}

?>