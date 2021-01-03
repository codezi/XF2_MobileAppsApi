<?php

namespace Truonglv\Api\XF\BbCode\Renderer;

use Truonglv\Api\App;
use XF\Entity\Attachment;

class ApiHtml extends XFCP_ApiHtml
{
    /**
     * @param array $children
     * @param mixed $option
     * @param array $tag
     * @param array $options
     * @return string
     */
    public function renderTagAttach(array $children, $option, array $tag, array $options)
    {
        $id = \intval($this->renderSubTreePlain($children));
        if ($id > 0 && App::isRequestFromApp()) {
            $attachments = $options['attachments'];

            if (isset($attachments[$id])) {
                /** @var Attachment $attachmentRef */
                $attachmentRef = $attachments[$id];

                $params = [
                    'id' => $id,
                    'attachment' => $attachmentRef,
                    'full' => $this->isFullAttachView($option),
                    'alt' => $attachmentRef->filename,
                    'attachmentViewUrl' => App::buildAttachmentLink($attachmentRef),
                ];

                return $this->templater->renderTemplate('public:tapi_bb_code_tag_attach_img', $params);
            }
        }

        return parent::renderTagAttach($children, $option, $tag, $options);
    }

    /**
     * @param array $children
     * @param mixed $option
     * @param array $tag
     * @param array $options
     * @return mixed
     */
    public function renderTagMedia(array $children, $option, array $tag, array $options)
    {
        if (!App::isRequestFromApp()) {
            return parent::renderTagMedia($children, $option, $tag, $options);
        }

        $mediaKey = \trim($this->renderSubTreePlain($children));
        if (\preg_match('#[&?"\'<>\r\n]#', $mediaKey) === 1 || \strpos($mediaKey, '..') !== false) {
            return '';
        }

        $censored = $this->formatter->censorText($mediaKey);
        if ($censored != $mediaKey) {
            return '';
        }

        $provider = \strtolower($option);
        if ($provider === 'youtube') {
            $viewUrl = 'https://youtube.com/watch?v=' . $mediaKey;
            $thumbnailUrl = 'https://img.youtube.com/vi/' . $mediaKey . '/hqdefault.jpg';

            return $this->wrapHtml(
                sprintf(
                    '<video src="%s" data-thumbnail="%s" data-provider="%s">',
                    htmlspecialchars($viewUrl),
                    htmlspecialchars($thumbnailUrl),
                    htmlspecialchars($provider)
                ),
                '',
                '</video>'
            );
        }

        return '[EMBED MEDIA]';
    }

    /**
     * @param mixed $url
     * @param array $options
     * @return string
     */
    protected function prepareTextFromUrlExtended($url, array $options)
    {
        if (App::isRequestFromApp()) {
            $options['shortenUrl'] = true;
        }

        return parent::prepareTextFromUrlExtended($url, $options);
    }

    /**
     * @param mixed $text
     * @param mixed $url
     * @param array $options
     * @return string
     */
    protected function getRenderedLink($text, $url, array $options)
    {
        $visitor = \XF::visitor();
        $proxyUrl = $url;
        $linkInfo = $this->formatter->getLinkClassTarget($url);

        if ($visitor->user_id > 0 && $linkInfo['trusted'] === true && App::isRequestFromApp()) {
            $proxyUrl = App::buildLinkProxy($url);
        }

        $html = parent::getRenderedLink($text, $proxyUrl, $options);
        $html = \trim($html);

        if ($linkInfo['type'] === 'internal') {
            $app = \XF::app();
            if (\strpos($url, $app->options()->boardUrl) === 0) {
                $url = \substr($url, \strlen($app->options()->boardUrl));
            }
            $url = \ltrim($url, '/');

            $request = new \XF\Http\Request(\XF::app()->inputFilterer(), [], [], [], []);
            $match = $app->router('public')->routeToController($url, $request);
            $matchController = $match->getController();

            $supportControllers = [
                'XF:Category',
                'XF:Forum',
                'XF:Member',
                'XF:Post',
                'XF:Thread',
            ];
            if (\in_array($matchController, $supportControllers, true)) {
                $params = (string) \json_encode($match->getParams());
                $html = \substr($html, 0, 3)
                    . ' data-tapi-route="' . \htmlspecialchars($matchController) . '"'
                    . ' data-tapi-route-params="' . \htmlspecialchars($params) . '" '
                    . \substr($html, 3);
            }
        }

        return $html;
    }
}
