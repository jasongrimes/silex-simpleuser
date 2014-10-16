<?php

namespace SimpleUser\Tests;

use SimpleUser\Mailer;
use SimpleUser\User;

class MailerTest extends \PHPUnit_Framework_TestCase
{
    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $swiftmailer;

    /** @var Mailer */
    protected $mailer;

    /** @var \Twig_Environment */
    protected $twig;

    protected $confirmationUrl = 'http://www.example.com/12345';

    protected $templateKey = 'template-key';

    protected function setUp()
    {
        $this->swiftmailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();

        $urlGenerator = $this->getMockBuilder('Symfony\Component\Routing\Generator\UrlGenerator')
            ->disableOriginalConstructor()
            ->getMock();
        $urlGenerator->method('generate')
            ->willReturn($this->confirmationUrl);

        $templates = array($this->templateKey => '
{% block subject %}
{% autoescape false %}
Subject line
{% endautoescape %}
{% endblock %}
{% block body_text %}
{% autoescape false %}
Hello, {{ user.name }}. {{ confirmationUrl }}
{% endautoescape %}
{% endblock %}
{% block body_html %}{% endblock %}');
        $this->twig = new \Twig_Environment(new \Twig_Loader_Array($templates));

        $this->mailer = new Mailer($this->swiftmailer, $urlGenerator, $this->twig);
        $this->mailer->setConfirmationTemplate($this->templateKey);
    }

    public function testCreateInstance()
    {
        $this->assertInstanceOf('SimpleUser\Mailer', $this->mailer);
    }

    public function testSendConfirmationMessage()
    {
        $this->mailer->setFromAddress('from@example.com');
        $this->mailer->setFromName('From Name');

        $user = new User('to@example.com');

        $this->swiftmailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function($message) use ($user) {
                if (!$message instanceof \Swift_Message) return false;
                $msg = $message->toString();
                $patterns = array(
                    '/From: From Name <from@example.com>/',
                    '/To: to@example.com/',
                    '/Hello, ' . $user->getName() . '/'
                );
                foreach ($patterns as $pattern) {
                    if (!preg_match($pattern, $msg)) {
                        echo 'Message failed to match pattern "' . $pattern . '".';
                        return false;
                    }
                }

                return true;
            }));

        $this->mailer->sendConfirmationMessage($user);
    }

    /**
     * @expectedException \Twig_Error_Loader
     */
    public function testSendThrowsExceptionIfTemplateInvalid()
    {
        $this->mailer->setConfirmationTemplate(null);

        $this->mailer->sendConfirmationMessage(new User('test@example.com'));

    }

    public function testSendAllowsEmptyFrom()
    {
        $this->mailer->setFromAddress(null);
        $this->mailer->setFromName(null);
        $this->mailer->sendConfirmationMessage(new User('test@example.com'));

        $this->mailer->setFromAddress('');
        $this->mailer->setFromName('');
        $this->mailer->sendConfirmationMessage(new User('test@example.com'));
    }

    public function testDisableSending()
    {
        $this->mailer->setNoSend(true);

        $this->swiftmailer->expects($this->never())
            ->method('send');

        $this->mailer->sendConfirmationMessage(new User('test@example.com'));
    }

    /*
    // A quick way to see what the rendered email template looks like.
    // Not a real unit test; normally this should be commented out.
    public function testEchoDefaultTemplate()
    {
        $this->twig->setLoader(new \Twig_Loader_Filesystem(__DIR__ . '/../../../src/SimpleUser/views/email'));
        $this->mailer->setConfirmationTemplate('confirm-email.twig');

        $this->swiftmailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function($message) {
                echo $message . "\n";
                return true;
            }));

        $this->mailer->sendConfirmationMessage(new User('test@example.com'));
    }
    */
}