<?php
/*
 * This file is based substantially on code from the FOSUserBundle package
 * <https://github.com/FriendsOfSymfony/FOSUserBundle>
 * which was released under the following license:
 *
 * Copyright (c) 2010-2011 FriendsOfSymfony
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished
 * to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace SimpleUser;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use \Swift_Mailer;
use \Twig_Environment;

class Mailer
{
    const ROUTE_CONFIRM_EMAIL = 'user.confirm-email';
    const ROUTE_RESET_PASSWORD = 'user.reset-password';

    /** @var \Swift_Mailer */
    protected $mailer;

    /** @var bool Whether to disable sending emails (ex. for dev environments). */
    protected $noSend = false;

    /** @var UrlGeneratorInterface  */
    protected $urlGenerator;

    /** @var \Twig_Environment */
    protected $twig;

    protected $fromAddress;
    protected $fromName;
    protected $confirmationTemplate;
    protected $resetTemplate;
    protected $resetTokenTtl = 86400;

    public function __construct(\Swift_Mailer $mailer, UrlGeneratorInterface $urlGenerator, \Twig_Environment $twig)
    {
        $this->mailer = $mailer;
        $this->urlGenerator = $urlGenerator;
        $this->twig = $twig;
    }

    /**
     * @param string $confirmationTemplate
     */
    public function setConfirmationTemplate($confirmationTemplate)
    {
        $this->confirmationTemplate = $confirmationTemplate;
    }

    /**
     * @return string
     */
    public function getConfirmationTemplate()
    {
        return $this->confirmationTemplate;
    }

    /**
     * @param string $fromAddress
     */
    public function setFromAddress($fromAddress)
    {
        $this->fromAddress = $fromAddress;
    }

    /**
     * @return string
     */
    public function getFromAddress()
    {
        return $this->fromAddress;
    }

    /**
     * @param string $fromName
     */
    public function setFromName($fromName)
    {
        $this->fromName = $fromName;
    }

    /**
     * @return string
     */
    public function getFromName()
    {
        return $this->fromName;
    }

    /**
     * @param string $resetTemplate
     */
    public function setResetTemplate($resetTemplate)
    {
        $this->resetTemplate = $resetTemplate;
    }

    /**
     * @return string
     */
    public function getResetTemplate()
    {
        return $this->resetTemplate;
    }

    /**
     * @param int $resetTokenTtl
     */
    public function setResetTokenTtl($resetTokenTtl)
    {
        $this->resetTokenTtl = $resetTokenTtl;
    }

    /**
     * @return int
     */
    public function getResetTokenTtl()
    {
        return $this->resetTokenTtl;
    }

    public function sendConfirmationMessage(User $user)
    {
        $url = $this->urlGenerator->generate(self::ROUTE_CONFIRM_EMAIL, array('token' => $user->getConfirmationToken()), true);

        $context = array(
            'user' => $user,
            'confirmationUrl' => $url
        );

        $this->sendMessage($this->confirmationTemplate, $context, $this->getFromEmail(), $user->getEmail());
    }

    public function sendResetMessage(User $user)
    {
        $url = $this->urlGenerator->generate(self::ROUTE_RESET_PASSWORD, array('token' => $user->getConfirmationToken()), true);

        $context = array(
            'user' => $user,
            'resetUrl' => $url
        );

        $this->sendMessage($this->resetTemplate, $context, $this->getFromEmail(), $user->getEmail());
    }

    /**
     * Format the fromEmail parameter for the Swift_Mailer.
     *
     * @return array|string|null
     */
    protected function getFromEmail()
    {
        if (!$this->fromAddress) {
            return null;
        }

        if ($this->fromName) {
            return array($this->fromAddress => $this->fromName);
        }

        return $this->fromAddress;
    }


    /**
     * @param string $templateName
     * @param array  $context
     * @param string|array $fromEmail
     * @param string|array $toEmail
     */
    protected function sendMessage($templateName, $context, $fromEmail, $toEmail)
    {
        if ($this->noSend) {
            return;
        }

        $context = $this->twig->mergeGlobals($context);
        $template = $this->twig->loadTemplate($templateName);
        $subject = $template->renderBlock('subject', $context);
        $textBody = $template->renderBlock('body_text', $context);
        $htmlBody = $template->renderBlock('body_html', $context);

        $message = \Swift_Message::newInstance()
            ->setSubject($subject)
            ->setFrom($fromEmail)
            ->setTo($toEmail);

        if (!empty($htmlBody)) {
            $message->setBody($htmlBody, 'text/html')
                ->addPart($textBody, 'text/plain');
        } else {
            $message->setBody($textBody);
        }

        $this->mailer->send($message);
    }

    /**
     * @param boolean $noSend
     */
    public function setNoSend($noSend)
    {
        $this->noSend = (bool) $noSend;
    }
}