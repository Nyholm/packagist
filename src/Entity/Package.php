<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use App\Service\UpdaterWorker;
use Composer\Downloader\TransportException;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Repository\VcsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Composer\Repository\Vcs\GitHubDriver;
use Composer\Util\HttpDownloader;
use DateTimeInterface;

/**
 * @ORM\Entity(repositoryClass="App\Entity\PackageRepository")
 * @ORM\Table(
 *     name="package",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="package_name_idx", columns={"name"})},
 *     indexes={
 *         @ORM\Index(name="indexed_idx",columns={"indexedAt"}),
 *         @ORM\Index(name="crawled_idx",columns={"crawledAt"}),
 *         @ORM\Index(name="dumped_idx",columns={"dumpedAt"}),
 *         @ORM\Index(name="dumped2_idx",columns={"dumpedAtV2"}),
 *         @ORM\Index(name="repository_idx",columns={"repository"}),
 *         @ORM\Index(name="remoteid_idx",columns={"remoteId"})
 *     }
 * )
 * @Assert\Callback(callback="isPackageUnique")
 * @Assert\Callback(callback="isVendorWritable")
 * @Assert\Callback(callback="isRepositoryValid", groups={"Update", "Default"})
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Package
{
    const AUTO_MANUAL_HOOK = 1;
    const AUTO_GITHUB_HOOK = 2;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * Unique package name
     *
     * @ORM\Column(length=191)
     */
    private string $name = '';

    /**
     * @ORM\Column(nullable=true)
     */
    private $type;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $description;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $language;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $readme;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_stars")
     */
    private $gitHubStars;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_watches")
     */
    private $gitHubWatches;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_forks")
     */
    private $gitHubForks;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_open_issues")
     */
    private $gitHubOpenIssues;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Version", mappedBy="package")
     */
    private $versions;

    /**
     * @ORM\ManyToMany(targetEntity="User", inversedBy="packages")
     * @ORM\JoinTable(name="maintainers_packages")
     */
    private $maintainers;

    /**
     * @ORM\Column()
     * @Assert\NotBlank(groups={"Update", "Default"})
     */
    private $repository;

    // dist-tags / rel or runtime?

    /**
     * @ORM\Column(type="datetime")
     */
    private DateTimeInterface $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?DateTimeInterface $updatedAt = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?DateTimeInterface $crawledAt = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?DateTimeInterface $indexedAt = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?DateTimeInterface $dumpedAt = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?DateTimeInterface $dumpedAtV2 = null;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Download", mappedBy="package")
     */
    private $downloads;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $remoteId;

    /**
     * @ORM\Column(type="smallint")
     */
    private $autoUpdated = 0;

    /**
     * @var bool
     * @ORM\Column(type="boolean")
     */
    private $abandoned = false;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $replacementPackage = null;

    /**
     * @ORM\Column(type="boolean", options={"default"=false})
     */
    private $updateFailureNotified = false;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $suspect;

    /**
     * @var string|null
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $tagPattern;

    private $entityRepository;
    private $router;

    /**
     * @var true|null|\Composer\Repository\Vcs\VcsDriverInterface
     */
    private $vcsDriver = true;
    private $vcsDriverError;

    /**
     * @var array lookup table for versions
     */
    private $cachedVersions;

    public function __construct()
    {
        $this->versions = new ArrayCollection();
        $this->createdAt = new \DateTime;
    }

    public function toArray(VersionRepository $versionRepo, bool $serializeForApi = false)
    {
        $maintainers = [];
        foreach ($this->getMaintainers() as $maintainer) {
            $maintainers[] = $maintainer->toArray();
        }

        $versions = [];
        $partialVersions = $this->getVersions()->toArray();
        while ($partialVersions) {
            $versionRepo->getEntityManager()->clear();

            $slice = array_splice($partialVersions, 0, 100);
            $fullVersions = $versionRepo->refreshVersions($slice);
            $versionData = $versionRepo->getVersionData(array_map(function ($v) { return $v->getId(); }, $fullVersions));
            $versions = array_merge($versions, $versionRepo->detachToArray($fullVersions, $versionData, $serializeForApi));
        }

        $data = [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'time' => $this->getCreatedAt()->format('c'),
            'maintainers' => $maintainers,
            'versions' => $versions,
            'type' => $this->getType(),
            'repository' => $this->getRepository(),
            'github_stars' => $this->getGitHubStars(),
            'github_watchers' => $this->getGitHubWatches(),
            'github_forks' => $this->getGitHubForks(),
            'github_open_issues' => $this->getGitHubOpenIssues(),
            'language' => $this->getLanguage(),
        ];

        if ($this->isAbandoned()) {
            $data['abandoned'] = $this->getReplacementPackage() ?: true;
        }

        return $data;
    }

    public function isRepositoryValid(ExecutionContextInterface $context)
    {
        // vcs driver was not nulled which means the repository was not set/modified and is still valid
        if (true === $this->vcsDriver && '' !== $this->name) {
            return;
        }

        $property = 'repository';
        $driver = $this->vcsDriver;
        if (!is_object($driver)) {
            if (preg_match('{^http://}', $this->repository)) {
                $context->buildViolation('Non-secure HTTP URLs are not supported, make sure you use an HTTPS or SSH URL')
                    ->atPath($property)
                    ->addViolation()
                ;
            } elseif (preg_match('{https?://.+@}', $this->repository)) {
                $context->buildViolation('URLs with user@host are not supported, use a read-only public URL')
                    ->atPath($property)
                    ->addViolation()
                ;
            } elseif (is_string($this->vcsDriverError)) {
                $context->buildViolation('Uncaught Exception: '.htmlentities($this->vcsDriverError, ENT_COMPAT, 'utf-8'))
                    ->atPath($property)
                    ->addViolation()
                ;
            } else {
                $context->buildViolation('No valid/supported repository was found at the given URL')
                    ->atPath($property)
                    ->addViolation()
                ;
            }
            return;
        }
        try {
            $information = $driver->getComposerInformation($driver->getRootIdentifier());
            if (empty($information['name'])) {
                $context->buildViolation('The package name was not found in the composer.json, make sure there is a name present.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (!preg_match('{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$}iD', $information['name'])) {
                $context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is invalid, it should have a vendor name, a forward slash, and a package name. The vendor and package name can be words separated by -, . or _. The complete name should match "[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*".')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (
                preg_match('{(free.*watch|watch.*free|(stream|online).*anschauver.*pelicula|ver.*completa|pelicula.*complet|season.*episode.*online|film.*(complet|entier)|(voir|regarder|guarda|assistir).*(film|complet)|full.*movie|online.*(free|tv|full.*hd)|(free|full|gratuit).*stream|movie.*free|free.*(movie|hack)|watch.*movie|watch.*full|generate.*resource|generate.*unlimited|hack.*coin|coin.*(hack|generat)|vbucks|hack.*cheat|hack.*generat|generat.*hack|hack.*unlimited|cheat.*(unlimited|generat)|(mod|cheat|apk).*(hack|cheat|mod)|hack.*(apk|mod|free|gold|gems|diamonds|coin)|putlocker|generat.*free|coins.*generat|(download|telecharg).*album|album.*(download|telecharg)|album.*(free|gratuit)|generat.*coins|unlimited.*coins|(fortnite|pubg|apex.*legend|t[1i]k.*t[o0]k).*(free|gratuit|generat|unlimited|coins|mobile|hack|follow))}i', str_replace(['.', '-'], '', $information['name']))
                && !preg_match('{^(hexmode|calgamo|liberty_code(_module)?|dvi|thelia|clayfreeman|watchfulli|assaneonline|awema-pl|magemodules?|simplepleb|modullo)/}', $information['name'])
            ) {
                $context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is blocked, if you think this is a mistake please get in touch with us.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            $reservedNames = ['nul', 'con', 'prn', 'aux', 'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9', 'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9'];
            $bits = explode('/', strtolower($information['name']));
            if (in_array($bits[0], $reservedNames, true) || in_array($bits[1], $reservedNames, true)) {
                $context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is reserved, package and vendor names can not match any of: '.implode(', ', $reservedNames).'.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (preg_match('{\.json$}', $information['name'])) {
                $context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is invalid, package names can not end in .json, consider renaming it or perhaps using a -json suffix instead.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (preg_match('{[A-Z]}', $information['name'])) {
                $suggestName = preg_replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $information['name']);
                $suggestName = strtolower($suggestName);

                $context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is invalid, it should not contain uppercase characters. We suggest using '.$suggestName.' instead.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }
        } catch (\Exception $e) {
            if ($e instanceof TransportException && $e->getCode() === 404) {
                $context->buildViolation('No composer.json was found in the '.$driver->getRootIdentifier().' branch.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            $context->buildViolation('We had problems parsing your composer.json file, the parser reports: '.htmlentities($e->getMessage(), ENT_COMPAT, 'utf-8'))
                ->atPath($property)
                ->addViolation()
            ;
            return;
        }

        if ('' === $this->name) {
            $context->buildViolation('An unexpected error has made our parser fail to find a package name in your repository, if you think this is incorrect please try again')
                ->atPath($property)
                ->addViolation()
            ;
        }
    }

    public function setEntityRepository($repository)
    {
        $this->entityRepository = $repository;
    }

    public function setRouter($router)
    {
        $this->router = $router;
    }

    public function isPackageUnique(ExecutionContextInterface $context)
    {
        try {
            if ($this->entityRepository->findOneByName($this->name)) {
                $context->buildViolation('A package with the name <a href="'.$this->router->generate('view_package', ['name' => $this->name]).'">'.$this->name.'</a> already exists.')
                    ->atPath('repository')
                    ->addViolation()
                ;
            }
        } catch (\Doctrine\ORM\NoResultException $e) {}
    }

    public function isVendorWritable(ExecutionContextInterface $context)
    {
        try {
            $vendor = $this->getVendor();
            if ($vendor && $this->entityRepository->isVendorTaken($vendor, reset($this->maintainers))) {
                $context->buildViolation('The vendor name "'.$vendor.'" was already claimed by someone else on Packagist.org. '
                        . 'You may ask them to add your package and give you maintainership access. '
                        . 'If they add you as a maintainer on any package in that vendor namespace, '
                        . 'you will then be able to add new packages in that namespace. '
                        . 'The packages already in that vendor namespace can be found at '
                        . '<a href="'.$this->router->generate('view_vendor', ['vendor' => $vendor]).'">'.$vendor.'</a>.'
                        . 'If those packages belong to you but were submitted by someone else, you can <a href="mailto:contact@packagist.org">contact us</a> to resolve the issue.')
                    ->atPath('repository')
                    ->addViolation()
                ;
            }
        } catch (\Doctrine\ORM\NoResultException $e) {}
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setName(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        if ('' === $this->name) {
            throw new \LogicException('This should not be called on an invalid package object which was not initialized with a name yet');
        }

        return $this->name;
    }

    /**
     * Get vendor prefix
     *
     * @return string
     */
    public function getVendor()
    {
        return preg_replace('{/.*$}', '', $this->name);
    }

    /**
     * Get package name without vendor
     *
     * @return string
     */
    public function getPackageName()
    {
        return preg_replace('{^[^/]*/}', '', $this->name);
    }

    /**
     * Set description
     *
     * @param string $description
     */
    public function setDescription($description)
    {
        $this->description = $description;
    }

    /**
     * Get description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set language
     *
     * @param string $language
     */
    public function setLanguage($language)
    {
        $this->language = $language;
    }

    /**
     * Get language
     *
     * @return string
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * Set readme
     *
     * @param string $readme
     */
    public function setReadme($readme)
    {
        $this->readme = $readme;
    }

    /**
     * Get readme
     *
     * @return string
     */
    public function getReadme()
    {
        return $this->readme;
    }

    /**
     * Get readme with transformations that should not be done in the stored readme as they might not be valid in the long run
     *
     * @return string
     */
    public function getOptimizedReadme()
    {
        return str_replace(['<img src="https://raw.github.com/', '<img src="https://raw.githubusercontent.com/'], '<img src="https://rawcdn.githack.com/', $this->readme);
    }

    /**
     * @param int $val
     */
    public function setGitHubStars($val)
    {
        $this->gitHubStars = $val;
    }

    /**
     * @return int
     */
    public function getGitHubStars()
    {
        return $this->gitHubStars;
    }

    /**
     * @param int $val
     */
    public function setGitHubWatches($val)
    {
        $this->gitHubWatches = $val;
    }

    /**
     * @return int
     */
    public function getGitHubWatches()
    {
        return $this->gitHubWatches;
    }

    /**
     * @param int $val
     */
    public function setGitHubForks($val)
    {
        $this->gitHubForks = $val;
    }

    /**
     * @return int
     */
    public function getGitHubForks()
    {
        return $this->gitHubForks;
    }

    /**
     * @param int $val
     */
    public function setGitHubOpenIssues($val)
    {
        $this->gitHubOpenIssues = $val;
    }

    /**
     * @return int
     */
    public function getGitHubOpenIssues()
    {
        return $this->gitHubOpenIssues;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setRepository(string $repoUrl): void
    {
        $this->vcsDriver = null;

        // prevent local filesystem URLs
        if (preg_match('{^(\.|[a-z]:|/)}i', $repoUrl)) {
            return;
        }

        $repoUrl = preg_replace('{^git@github.com:}i', 'https://github.com/', $repoUrl);
        $repoUrl = preg_replace('{^git://github.com/}i', 'https://github.com/', $repoUrl);
        $repoUrl = preg_replace('{^(https://github.com/.*?)\.git$}i', '$1', $repoUrl);

        $repoUrl = preg_replace('{^git@gitlab.com:}i', 'https://gitlab.com/', $repoUrl);
        $repoUrl = preg_replace('{^(https://gitlab.com/.*?)\.git$}i', '$1', $repoUrl);

        $repoUrl = preg_replace('{^git@+bitbucket.org:}i', 'https://bitbucket.org/', $repoUrl);
        $repoUrl = preg_replace('{^bitbucket.org:}i', 'https://bitbucket.org/', $repoUrl);
        $repoUrl = preg_replace('{^https://[a-z0-9_-]*@bitbucket.org/}i', 'https://bitbucket.org/', $repoUrl);
        $repoUrl = preg_replace('{^(https://bitbucket.org/[^/]+/[^/]+)/src/[^.]+}i', '$1.git', $repoUrl);

        // normalize protocol case
        $repoUrl = preg_replace_callback('{^(https?|git|svn)://}i', function ($match) { return strtolower($match[1]) . '://'; }, $repoUrl);

        $this->repository = $repoUrl;
        $this->remoteId = null;

        // avoid user@host URLs
        if (preg_match('{https?://.+@}', $repoUrl)) {
            return;
        }

        // validate that this is a somewhat valid URL
        if (!preg_match('{^([a-z0-9][^@\s]+@[a-z0-9-_.]+:\S+ | [a-z0-9]+://\S+)$}Dx', $repoUrl)) {
            return;
        }

        try {
            $io = new NullIO();
            $config = Factory::createConfig();
            $io->loadConfiguration($config);
            $httpDownloader = new HttpDownloader($io, $config);
            $repository = new VcsRepository(['url' => $this->repository], $io, $config, $httpDownloader, null, null, UpdaterWorker::VCS_REPO_DRIVERS);

            $driver = $this->vcsDriver = $repository->getDriver();
            if (!$driver) {
                return;
            }
            $information = $driver->getComposerInformation($driver->getRootIdentifier());
            if (!isset($information['name'])) {
                return;
            }
            if ('' === $this->name) {
                $this->setName(trim($information['name']));
            }
            if ($driver instanceof GitHubDriver) {
                $this->repository = $driver->getRepositoryUrl();
                if ($repoData = $driver->getRepoData()) {
                    $this->remoteId = parse_url($this->repository, PHP_URL_HOST).'/'.$repoData['id'];
                }
            }
        } catch (\Exception $e) {
            $this->vcsDriverError = '['.get_class($e).'] '.$e->getMessage();
        }
    }

    /**
     * Get repository
     *
     * @return string $repository
     */
    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * Get a user-browsable version of the repository URL
     *
     * @return string $repository
     */
    public function getBrowsableRepository()
    {
        if (preg_match('{(://|@)bitbucket.org[:/]}i', $this->repository)) {
            return preg_replace('{^(?:git@|https://|git://)bitbucket.org[:/](.+?)(?:\.git)?$}i', 'https://bitbucket.org/$1', $this->repository);
        }

        return preg_replace('{^(git://github.com/|git@github.com:)}', 'https://github.com/', $this->repository);
    }

    /**
     * Add versions
     *
     * @param Version $versions
     */
    public function addVersions(Version $versions)
    {
        $this->versions[] = $versions;
    }

    /**
     * Get versions
     *
     * @return ArrayCollection<Version>
     */
    public function getVersions()
    {
        return $this->versions;
    }

    public function getVersion($normalizedVersion)
    {
        if (null === $this->cachedVersions) {
            $this->cachedVersions = [];
            foreach ($this->getVersions() as $version) {
                $this->cachedVersions[strtolower($version->getNormalizedVersion())] = $version;
            }
        }

        if (isset($this->cachedVersions[strtolower($normalizedVersion)])) {
            return $this->cachedVersions[strtolower($normalizedVersion)];
        }

        return null;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt)
    {
        $this->updatedAt = $updatedAt;
        $this->setUpdateFailureNotified(false);
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function wasUpdatedInTheLast24Hours(): bool
    {
        return $this->updatedAt && $this->updatedAt > new \DateTime('-24 hours');
    }

    public function setCrawledAt(?DateTimeInterface $crawledAt): void
    {
        $this->crawledAt = $crawledAt;
    }

    public function getCrawledAt(): ?DateTimeInterface
    {
        return $this->crawledAt;
    }

    public function setIndexedAt(?DateTimeInterface $indexedAt): void
    {
        $this->indexedAt = $indexedAt;
    }

    public function getIndexedAt(): ?DateTimeInterface
    {
        return $this->indexedAt;
    }

    public function setDumpedAt(?DateTimeInterface $dumpedAt): void
    {
        $this->dumpedAt = $dumpedAt;
    }

    public function getDumpedAt(): ?DateTimeInterface
    {
        return $this->dumpedAt;
    }

    public function setDumpedAtV2(?DateTimeInterface $dumpedAt): void
    {
        $this->dumpedAtV2 = $dumpedAt;
    }

    public function getDumpedAtV2(): ?DateTimeInterface
    {
        return $this->dumpedAtV2;
    }

    /**
     * Add maintainers
     *
     * @param User $maintainer
     */
    public function addMaintainer(User $maintainer)
    {
        $this->maintainers[] = $maintainer;
    }

    /**
     * Get maintainers
     *
     * @return ArrayCollection<User>
     */
    public function getMaintainers()
    {
        return $this->maintainers;
    }

    /**
     * Set type
     *
     * @param string $type
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * Get type
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    public function setRemoteId(?string $remoteId)
    {
        $this->remoteId = $remoteId;
    }

    public function getRemoteId(): ?string
    {
        return $this->remoteId;
    }

    /**
     * Set autoUpdated
     *
     * @param int $autoUpdated
     */
    public function setAutoUpdated($autoUpdated)
    {
        $this->autoUpdated = $autoUpdated;
    }

    /**
     * Get autoUpdated
     *
     * @return int
     */
    public function getAutoUpdated()
    {
        return $this->autoUpdated;
    }

    /**
     * Get autoUpdated
     *
     * @return Boolean
     */
    public function isAutoUpdated()
    {
        return $this->autoUpdated > 0;
    }

    /**
     * Set updateFailureNotified
     *
     * @param Boolean $updateFailureNotified
     */
    public function setUpdateFailureNotified($updateFailureNotified)
    {
        $this->updateFailureNotified = $updateFailureNotified;
    }

    /**
     * Get updateFailureNotified
     *
     * @return Boolean
     */
    public function isUpdateFailureNotified()
    {
        return $this->updateFailureNotified;
    }

    public function setSuspect(?string $reason)
    {
        $this->suspect = $reason;
    }

    public function isSuspect(): bool
    {
        return !is_null($this->suspect);
    }

    public function getSuspect(): ?string
    {
        return $this->suspect;
    }

    /**
     * If defined, tags/versions must match this pattern or they will be ignored.
     */
    public function getTagPattern(): ?string
    {
        return $this->tagPattern;
    }

    /**
     * @param string $tagPattern
     */
    public function setTagPattern(string $tagPattern): void
    {
        if ($tagPattern === '') {
            $tagPattern = null;
        }
        $this->tagPattern = $tagPattern;
    }

    /**
     * @return boolean
     */
    public function isAbandoned()
    {
        return $this->abandoned;
    }

    /**
     * @param boolean $abandoned
     */
    public function setAbandoned($abandoned)
    {
        $this->abandoned = $abandoned;
    }

    public function getReplacementPackage(): ?string
    {
        return $this->replacementPackage;
    }

    public function setReplacementPackage(?string $replacementPackage)
    {
        $this->replacementPackage = $replacementPackage;
    }

    public static function sortVersions($a, $b)
    {
        $aVersion = $a->getNormalizedVersion();
        $bVersion = $b->getNormalizedVersion();

        // use branch alias for sorting if one is provided
        if (isset($a->getExtra()['branch-alias'][$aVersion])) {
            $aVersion = preg_replace('{(.x)?-dev$}', '.9999999-dev', $a->getExtra()['branch-alias'][$aVersion]);
        }
        if (isset($b->getExtra()['branch-alias'][$bVersion])) {
            $bVersion = preg_replace('{(.x)?-dev$}', '.9999999-dev', $b->getExtra()['branch-alias'][$bVersion]);
        }

        $aVersion = preg_replace('{^dev-.*}', '0.0.0-alpha', $aVersion);
        $bVersion = preg_replace('{^dev-.*}', '0.0.0-alpha', $bVersion);

        // sort default branch first if it is non numeric
        if ($aVersion === '0.0.0-alpha' && $a->isDefaultBranch()) {
            return -1;
        }
        if ($bVersion === '0.0.0-alpha' && $b->isDefaultBranch()) {
            return 1;
        }

        // equal versions are sorted by date
        if ($aVersion === $bVersion) {
            // make sure sort is stable
            if ($a->getReleasedAt() == $b->getReleasedAt()) {
                return $a->getNormalizedVersion() <=> $b->getNormalizedVersion();
            }
            return $b->getReleasedAt() > $a->getReleasedAt() ? 1 : -1;
        }

        // the rest is sorted by version
        return version_compare($bVersion, $aVersion);
    }
}
