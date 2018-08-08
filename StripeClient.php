<?php
 
namespace System\StripeBundle\Client;
 
use Ello\UserBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Symfony\Component\Translation\Translator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Stripe\Charge;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\Plan;
 
class StripeClient
{
  private $config;
  private $em;
  private $logger;
  protected $container;
  private $translator;
 
  public function __construct(EntityManagerInterface $em, LoggerInterface $logger)
  {
      global $kernel;
      $this->em = $em;
      $this->container = $kernel->getContainer(); //$container;
      $this->logger = $this->container->get('logger');//$logger;
      $this->translator = $this->container->get('translator'); //$translator;
      $config = $this->container->getParameter('system_stripe');
      $this->config = $config['payment'];
      Stripe::setApiKey($config['keys']['stripe_secret_key']);
        
  }
 
  public function getCustomer(User $user, $token = false)
  {
      try {
          if($user->getCustomerId()){
              $customer = Customer::retrieve($user->getCustomerId());
              if($customer && $token){
                  $customer->source = $token;
                  $customer->save();
              }
          } elseif($token) {
              $customer = Customer::create([
                  "description" => "Customer {$user->getEmail()}",
                  'email' => $user->getEmail(),
                  "source" => $token
              ]);
          }
          if($customer) {
              $user->setCustomerId($customer->id);
              $this->em->persist($user);
              $this->em->flush();
              return ['status' => true, 'data' => $customer];
          } else {
              return ['status' => false, 'message' => $this->translator->trans('Отсутствует информация о клиенте', [],'messages')];
          }
      } catch (\Stripe\Error\Base $e) {
          return ['status' => false, 'message' => $e->getMessage()];
      }
  }

  public function applySubscription(User $user, $plan = 'IradioTestMonthly')
  {
      try {
          if($user->getCustomerId()){
              $options = [
                  'customer' => $user->getCustomerId(),
                  'items' => [
                      [
                          'plan' => $plan
                      ]
                  ]
              ];
              $customer = Subscription::create($options);
              return ['status' => true, 'data' => $customer];
          } else {
              return ['status' => false, 'message' => $this->translator->trans('Отсутствует платёжная информация', [],'messages')];
          }
          return $customer;
      } catch (\Stripe\Error\Base $e) {
          return ['status' => false, 'message' => $e->getMessage()];
      }
  }
  
  public function unSubscribe(User $user) {
      try {
          $customer = $this->getCustomer($user);
          if($customer['status']){
              $subscription = $customer['data']['subscriptions']['data'];
              $subscription = isset($subscription[0]) ? $subscription[0]['id'] : false;
              if($subscription){
                  $result = Subscription::retrieve($subscription);
                  $result->cancel();
                  return ['status' => true, 'data' => $subscription];
              } else {
                  return ['status' => false, 'message' => $this->translator->trans('Подписка отсутствует', [],'messages')];
              }
          } else {
              return ['status' => false, 'message' => $this->translator->trans('Отсутствует платёжная информация', [],'messages')];
          }
          return $customer;
      } catch (\Stripe\Error\Base $e) {
          return ['status' => false, 'message' => $e->getMessage()];
      }
      
  }
  
  public function listPlans()
  {
      try {
          $plans = Plan::all();
          $items = [];
          foreach ($plans['data'] as $plan){
              $items[] = [
                  'id' => $plan['id'],
                  'price' => $plan['amount'],
                  'currency' => $plan['currency'],
                  'interval' => $plan['interval'],
              ];
          }
          return ['status' => true, 'data' => $items];
      } catch (\Stripe\Error\Base $e) {
          return ['status' => false, 'message' => $e->getMessage()];
      }
  }
}