<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Newsletter;
use AppBundle\Entity\Page;
use AppBundle\Entity\Post;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints\Email as EmailConstraint;

class DefaultController extends Controller
{
    /**
     * @Route("/", name="homepage")
     * @Template
     */
    public function indexAction(Request $request)
    {
        $query = $this->getDoctrine()->getRepository('AppBundle:Post')->getIndexQuery();
        $paginator = $this->get('knp_paginator');
        $page = $request->query->getInt('page', 1);
        $limit = 10;
        $posts = $paginator->paginate($query, $page, $limit);

        return array('posts' => $posts);
    }

    /**
     * @Route("/page/listing", name="listing")
     * @Template
     */
    public function listingAction()
    {
        $url = "http://www.ffbsq.org/bowling/listing/listing-ws.php?output=xml&asfile=false&num_licence=&departement=&region=&club=poitevin&nom=";

        $crawler = new Crawler();
        $crawler->addXmlContent(file_get_contents($url));
        $listing = array();
        $exclude = array('club');

        foreach($crawler as $xListing){
            foreach($xListing->childNodes as $joueur){
                $tmpJoueur = array();

                foreach($joueur->attributes as $attr){
                    if(!in_array($attr->name, $exclude)){
                        $tmpJoueur[$attr->name] = $joueur->getAttribute($attr->name);
                    }
                }

                $listing[] = $tmpJoueur;
            }
        }

        $dynamicArray = $this->getDynamicArray($listing);
        array_multisort($dynamicArray['moyenne'], SORT_DESC, $dynamicArray['nom'], SORT_ASC, $listing);

        return array('listing' => $listing);
    }

    /**
     * @Route("/page/gallerie", name="gallery")
     * @Template
     */
    public function galleryAction(Request $request)
    {
        $query = $this->getDoctrine()->getRepository('AppBundle:Image')->getGalleryQuery();
        $paginator = $this->get('knp_paginator');
        $page = $request->query->getInt('page', 1);
        $limit = 10;
        $images = $paginator->paginate($query, $page, $limit);

        return array('images' => $images, 'limit' => $limit);
    }

    /**
     * @Route("/post/{slug}", name="post_view")
     * @Template("@App/default/post/view.html.twig")
     * @ParamConverter("post", options={"slug": "slug"})
     */
    public function postViewAction(Post $post){}

    /**
     * @Route("/page/{slug}", name="page_view")
     * @Template("@App/default/page/view.html.twig")
     * @ParamConverter("page", options={"slug": "slug"})
     */
    public function pageViewAction(Page $page){}

    /**
     * @Route("/archives/archive_bcp_{years}", name="archives")
     */
    public function archivesAction($years)
    {
        $template = '@App/default/archives/bcp_' . $years . '.html.twig';

        if(!$this->get('templating')->exists($template)){
            throw $this->createNotFoundException();
        }

        return $this->render($template);
    }

    /**
     * @Route("/newsletter/register", name="newsletter_register")
     * @Method("POST")
     */
    public function registerNewsletterAction(Request $request)
    {
        $mail = $request->request->get('mail_address');
        $emailConstraint = new EmailConstraint();
        $emailConstraint->message = 'Votre adresse mail est invalide...';
        $errors = $this->get('validator')->validate($mail, $emailConstraint);

        $em = $this->getDoctrine()->getManager();
        $newsletterAlreadyExist = $em->getRepository('AppBundle:Newsletter')->findOneBy(array('mail' => $mail));
        $userMailAlreadyExist = $em->getRepository('UserBundle:User')->findOneBy(array('email' => $mail));

        if(null !== $newsletterAlreadyExist || null !== $userMailAlreadyExist){
            $this->addFlash('danger', 'Cette adresse mail a déjà été ajoutée.');
        } elseif($errors->count() === 0 && $mail != ''){
            $newsletter = new Newsletter();
            $newsletter->setMail($mail);
            $newsletter->setToken($this->get('fos_user.util.token_generator')->generateToken());

            $em->persist($newsletter);
            $em->flush();

            $flash = sprintf('L\'adresse mail "%s" a bien été ajoutée. Vous recevrez dès à présent nos nouveautés.', $mail);
            $this->addFlash('success', $flash);
        } else {
            $this->addFlash('danger', $emailConstraint->message);
        }

        return $this->redirect($this->generateUrl('homepage'));
    }

    /**
     * @Route("/cookie/consent", name="cookie_consent")
     * @Method("GET")
     */
    public function cookieConsentAction()
    {
        $cookie = new Cookie('cookie_consent', 'true', time() + (10 * 365 * 24 * 60 * 60));
        $response = new RedirectResponse($this->generateUrl('homepage'));

        $response->headers->setCookie($cookie);

        return $response;
    }

    /**
     * @Route("/newsletter/remove/{token}", name="newsletter_remove")
     * @Method("GET")
     */
    public function removeNewsletterAction($token)
    {
        $em = $this->getDoctrine()->getManager();
        $newsletter = $em->getRepository('AppBundle:Newsletter')->findOneBy(array('token' => $token));

        if(null === $newsletter){
            $this->addFlash('danger', 'Le token de vérification ne correspond pas...');
        } else {
            $em->remove($newsletter);
            $em->flush();

            $this->addFlash('success', 'Vous ne recevrez plus nos nouveautés. Nous espérons tout de même vous revoir bientôt.');
        }

        return $this->redirect($this->generateUrl('homepage'));
    }

    /**
     * @Template
     */
    public function pagesAction()
    {
        $pages = $this->getDoctrine()->getRepository('AppBundle:Page')->findAllOrderByPriorityAndPublished();

        return array('pages' => $pages);
    }

    /**
     * Provide a dynamic array for array_multisort
     *
     * @param $array
     * @return array
     */
    private function getDynamicArray($array) {
        $retour = array();

        foreach ($array as $row) {
            foreach ($row as $k => $v) {
                $retour[$k][] = $v;
            }
        }

        return $retour;
    }
}
